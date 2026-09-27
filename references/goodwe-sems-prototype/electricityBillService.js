// ElectricityBillService — business logic for the electricity-bill module
// (Phase 3.6).
//
// Responsibilities:
//   - Create / list / get / update / delete bills (ownership-scoped)
//   - Validate billing inputs + reject overlapping periods
//   - Compute bill summary + trends from stored bills only
//   - Compare billing-period usage with historical solar generation
//     (contextual only — never real-time energy flow)
//
// Boundary enforced: billing consumption is billing-period context and is
// NEVER fed into the energy-balance engine (Phase 3.4). No self-consumption
// claim is made without measured household flow.

const billRepository = require("../repositories/electricityBillRepository");
const solarMeasurementRepository = require("../repositories/solarMeasurementRepository");
const { validateBillInput } = require("../validators/electricityBillValidator");
const { createSolarError } = require("../utils/solarErrors");
const config = require("../config/env");

class BillValidationError extends Error {
    constructor(message, errors = null) {
        super(message);
        this.name = "BillValidationError";
        this.statusCode = 400;
        this.errors = errors;
    }
    toJSON() {
        return {
            success: false,
            message: this.message,
            code: "VALIDATION_ERROR",
            ...(this.errors ? { errors: this.errors } : {}),
        };
    }
}

class BillNotFoundError extends Error {
    constructor() {
        super("Bill not found.");
        this.name = "BillNotFoundError";
        this.statusCode = 404;
    }
    toJSON() {
        return {
            success: false,
            message: "Bill not found.",
            code: "NOT_FOUND",
        };
    }
}

function requireUser(userId) {
    if (!userId) {
        throw createSolarError("Authentication required.", "AUTHORIZATION_ERROR");
    }
}

// Reject overlapping billing periods (unless the overlap is the same bill id).
function ensureNoOverlap({ userId, start, end, excludeId = null }) {
    const overlaps = billRepository.findOverlappingBills({ userId, start, end, excludeId });
    if (overlaps.length > 0) {
        const first = overlaps[0];
        throw new BillValidationError(
            `This billing period overlaps with an existing bill (${first.start} to ${first.end}).`,
            { billing_period_start: "Period overlaps with an existing bill.", billing_period_end: "Period overlaps with an existing bill." }
        );
    }
}

function createBill(userId, body) {
    requireUser(userId);
    const result = validateBillInput(body);
    if (result.errors) {
        throw new BillValidationError("Invalid bill information.", result.errors);
    }
    const d = result.data;
    ensureNoOverlap({ userId, start: d.billing_period_start, end: d.billing_period_end });

    return billRepository.insertBill({
        userId,
        billingPeriodStart: d.billing_period_start,
        billingPeriodEnd: d.billing_period_end,
        issueDate: d.issue_date,
        dueDate: d.due_date,
        previousMeterReading: d.previous_meter_reading,
        currentMeterReading: d.current_meter_reading,
        unitsConsumed: d.units_consumed,
        billAmount: d.bill_amount,
        fixedCharges: d.fixed_charges,
        energyCharges: d.energy_charges,
        taxes: d.taxes,
        tariffRate: d.tariff_rate,
        providerName: d.provider_name,
        meterNumber: d.meter_number,
        sourceType: d.source_type,
        documentReference: d.document_reference,
        notes: d.notes,
    });
}

function listBills(userId) {
    requireUser(userId);
    return billRepository.findBills({ userId });
}

function getBill(userId, id) {
    requireUser(userId);
    const bill = billRepository.findBillById({ userId, id });
    if (!bill) throw new BillNotFoundError();
    return bill;
}

function updateBill(userId, id, body) {
    requireUser(userId);
    const existing = billRepository.findBillById({ userId, id });
    if (!existing) throw new BillNotFoundError();

    // Validate the incoming fields (partial allowed for PUT-style merge).
    const result = validateBillInput(body, { partial: true });
    if (result.errors) {
        throw new BillValidationError("Invalid bill information.", result.errors);
    }

    const d = result.data;
    // Only update provided fields.
    const fields = {};
    const provided = new Set(Object.keys(body).map((k) => k.trim()));
    for (const [key, value] of Object.entries(d)) {
        if (provided.has(key) && value !== undefined) {
            fields[key] = value;
        }
    }

    const newStart = fields.billing_period_start || existing.billingPeriod.start;
    const newEnd = fields.billing_period_end || existing.billingPeriod.end;
    ensureNoOverlap({ userId, start: newStart, end: newEnd, excludeId: id });

    const updated = billRepository.updateBill({ userId, id, fields });
    if (!updated) throw new BillNotFoundError();
    return updated;
}

function deleteBill(userId, id) {
    requireUser(userId);
    const removed = billRepository.deleteBill({ userId, id });
    if (!removed) throw new BillNotFoundError();
    return { deleted: true, id };
}

// Summary values computed only from stored bills. Missing months are never
// treated as zero.
function getSummary(userId) {
    requireUser(userId);
    const bills = billRepository.findBills({ userId });

    if (bills.length === 0) {
        return {
            billCount: 0,
            latest: null,
            averageMonthlyConsumption: null,
            averageMonthlyBill: null,
            highestConsumption: null,
            lowestConsumption: null,
        };
    }

    const latest = bills[0]; // newest first
    const avgUnits = bills.reduce((a, b) => a + b.unitsConsumed, 0) / bills.length;
    const avgAmount = bills.reduce((a, b) => a + b.billAmount, 0) / bills.length;

    let highest = bills[0];
    let lowest = bills[0];
    for (const bill of bills) {
        if (bill.unitsConsumed > highest.unitsConsumed) highest = bill;
        if (bill.unitsConsumed < lowest.unitsConsumed) lowest = bill;
    }

    return {
        billCount: bills.length,
        latest,
        averageMonthlyConsumption: avgUnits,
        averageMonthlyBill: avgAmount,
        highestConsumption: highest,
        lowestConsumption: lowest,
    };
}

// Resolve the user's owned station/inverter (single-owner model, mirrors
// the historical/analytics services).
function resolveOwnedScope(userId) {
    const owned = solarMeasurementRepository.findOwnedPairs(userId);
    const stationId = config.solar.stationId
        || (owned.length > 0 ? owned[0].station_id : null)
        || null;
    const inverterSn = config.solar.inverterSn
        || (owned.length > 0 ? owned[0].inverter_sn : null)
        || null;
    return { stationId, inverterSn };
}

// Compare a bill's billing-period usage with historical solar generation.
// Returns contextual metrics — NEVER self-consumption. Solar data coverage is
// reported so the comparison is not presented as exact.
function getComparison(userId, billId) {
    requireUser(userId);
    const bill = billRepository.findBillById({ userId, id: billId });
    if (!bill) throw new BillNotFoundError();

    const { start, end } = bill.billingPeriod;
    const { stationId, inverterSn } = resolveOwnedScope(userId);

    // Historical solar daily aggregates over the exact billing period.
    const dayRows = solarMeasurementRepository.findDailyAggregates({
        userId,
        stationId,
        inverterSn,
        startTime: `${start}T00:00:00.000Z`,
        endTime: `${end}T23:59:59.999Z`,
    });

    const solarDays = dayRows.filter((r) => r.generation !== null && r.generation !== undefined);
    const solarGenerationKwh = solarDays.reduce((sum, r) => sum + r.generation, 0);

    // Coverage: days with solar generation data / total days in the period.
    // Day count computed on midnight boundaries (inclusive).
    const startMs = new Date(`${start}T00:00:00.000Z`).getTime();
    const endMs = new Date(`${end}T00:00:00.000Z`).getTime();
    const totalDays = Math.max(0, Math.round((endMs - startMs) / (24 * 60 * 60 * 1000)) + 1);
    const coveredDays = solarDays.length;
    const coveragePercent = totalDays > 0 ? parseFloat(((coveredDays / totalDays) * 100).toFixed(2)) : 0;

    const ratio = bill.unitsConsumed > 0
        ? parseFloat((solarGenerationKwh / bill.unitsConsumed).toFixed(3))
        : null;

    return {
        bill: {
            id: bill.id,
            billingPeriod: { start, end },
            unitsConsumed: bill.unitsConsumed,
            billAmount: bill.billAmount,
        },
        solar: {
            generationKwh: solarGenerationKwh,
            daysWithData: coveredDays,
            totalDays,
            coveragePercent,
        },
        generationToBilledUsageRatio: ratio,
        // Structured facts for a future AI layer.
        insight: {
            billingPeriod: { start, end },
            billConsumptionKwh: bill.unitsConsumed,
            billAmount: bill.billAmount,
            solarGenerationKwh,
            solarDataCompleteness: coveragePercent,
            generationToBilledUsageRatio: ratio,
            energyBalanceAvailable: false,
        },
    };
}

module.exports = {
    createBill,
    listBills,
    getBill,
    updateBill,
    deleteBill,
    getSummary,
    getComparison,
    BillValidationError,
    BillNotFoundError,
};