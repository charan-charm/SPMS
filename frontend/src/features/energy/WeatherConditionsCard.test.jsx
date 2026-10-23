import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { WeatherConditionsCard } from './WeatherConditionsCard'

describe('WeatherConditionsCard', () => {
    const sampleWeatherData = {
        household_id: 'hh-test-1',
        location: 'Kodumur, Kurnool, Andhra Pradesh',
        latitude: 15.6813,
        longitude: 77.772,
        timezone: 'Asia/Kolkata',
        provider: 'Open-Meteo',
        status: 'ACTIVE',
        freshness: 'FRESH',
        available: true,
        temperature: 29.5,
        cloud_cover: 18.0,
        direct_normal_irradiance: 520.0,
        shortwave_irradiance: 610.0,
        precipitation_probability: 5.0,
        precipitation: 0.0,
        condition: 'CLEAR',
        solar_impact: 'STRONG',
        solar_impact_description: 'Strong solar conditions expected with low cloud cover and high solar irradiance.',
        sunrise: '2026-10-09T06:05:00+05:30',
        sunset: '2026-10-09T18:10:00+05:30',
        hourly: [
            {
                hour: 10,
                is_daylight: true,
                cloud_cover_pct: 15,
                direct_normal_irradiance_wm2: 450,
                solar_impact: 'STRONG',
            },
            {
                hour: 12,
                is_daylight: true,
                cloud_cover_pct: 20,
                direct_normal_irradiance_wm2: 600,
                solar_impact: 'STRONG',
            },
        ],
    }

    const fallbackWeatherData = {
        household_id: 'hh-test-2',
        location: 'Kodumur',
        status: 'FALLBACK',
        freshness: 'FALLBACK',
        available: false,
        provider: 'Open-Meteo',
        temperature: null,
        cloud_cover: null,
        direct_normal_irradiance: null,
        solar_impact: 'MODERATE',
        limitations: ['External weather service unavailable: clear-sky physical solar pattern fallback model is active.'],
    }

    it('renders active weather evidence with atmospheric metrics and solar impact', () => {
        render(<WeatherConditionsCard weatherData={sampleWeatherData} />)

        expect(screen.getByTestId('weather-conditions-card')).toBeInTheDocument()
        expect(screen.getByText(/Weather & Solar Conditions/i)).toBeInTheDocument()
        expect(screen.getByText('ENVIRONMENTAL EVIDENCE')).toBeInTheDocument()
        expect(screen.getByText(/ACTIVE \/ FRESH/i)).toBeInTheDocument()
        expect(screen.getAllByText(/Strong solar conditions expected/i).length).toBeGreaterThanOrEqual(1)
        expect(screen.getByText('30°C')).toBeInTheDocument() // 29.5 rounded
        expect(screen.getByText('18%')).toBeInTheDocument()
        expect(screen.getByText('520 W/m²')).toBeInTheDocument()
        expect(screen.getByText(/Kodumur/i)).toBeInTheDocument()
    })

    it('renders honest fallback notice when external weather is unavailable without fabricating values', () => {
        render(<WeatherConditionsCard weatherData={fallbackWeatherData} />)

        expect(screen.getByTestId('weather-conditions-card')).toBeInTheDocument()
        expect(screen.getByText(/CLEAR-SKY FALLBACK/i)).toBeInTheDocument()
        expect(screen.getByText(/External weather evidence unavailable/i)).toBeInTheDocument()
        expect(screen.getByText(/clear-sky diurnal modeling/i)).toBeInTheDocument()
        expect(screen.queryByText('°C')).not.toBeInTheDocument()
    })

    it('clearly communicates environmental evidence semantics that weather does not measure actual generation', () => {
        render(<WeatherConditionsCard weatherData={sampleWeatherData} />)

        expect(screen.getByText(/Environmental Evidence Semantics/i)).toBeInTheDocument()
        expect(screen.getByText(/Actual solar output is recorded exclusively by your GoodWe inverter/i)).toBeInTheDocument()
    })

    it('displays daylight outlook with sunrise and sunset times', () => {
        render(<WeatherConditionsCard weatherData={sampleWeatherData} />)

        expect(screen.getByText(/Daylight Outlook & Solar Window/i)).toBeInTheDocument()
        expect(screen.getByText(/Sunrise:/i)).toBeInTheDocument()
        expect(screen.getByText(/Sunset:/i)).toBeInTheDocument()
    })
})
