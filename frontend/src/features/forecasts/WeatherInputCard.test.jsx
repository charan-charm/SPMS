import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { WeatherInputCard } from './WeatherInputCard'

describe('WeatherInputCard', () => {
    const sampleWeatherData = {
        status: 'WEATHER_ENHANCED',
        available: true,
        provider: 'OPEN_METEO',
        source_label: 'Open-Meteo',
        location_name: 'Tirupati, Andhra Pradesh, India',
        coordinates: {
            latitude: 13.63,
            longitude: 79.42,
        },
        retrieved_at: '2026-09-26T10:00:00Z',
        temperature_c: 31.5,
        cloud_cover_pct: 22.0,
        precipitation_probability_pct: 10.0,
        solar_irradiance_wm2: 650.0,
        condition: 'PARTLY_CLOUDY',
        clear_sky_index: 0.835,
    }

    it('renders weather data with temperature, cloud cover, precipitation, irradiance, and status badge', () => {
        render(<WeatherInputCard weather={sampleWeatherData} />)

        expect(screen.getByTestId('weather-input-card')).toBeInTheDocument()
        expect(screen.getByText('Weather-enhanced')).toBeInTheDocument()
        expect(screen.getByText('Tirupati, Andhra Pradesh, India')).toBeInTheDocument()
        expect(screen.getByText('31.5°C')).toBeInTheDocument()
        expect(screen.getByText('22%')).toBeInTheDocument()
        expect(screen.getByText('10%')).toBeInTheDocument()
        expect(screen.getByText('650 W/m²')).toBeInTheDocument()
    })

    it('displays the Open-Meteo source label clearly', () => {
        render(<WeatherInputCard weather={sampleWeatherData} />)

        expect(screen.getByText(/Source:/i)).toBeInTheDocument()
        expect(screen.getByText('Open-Meteo')).toBeInTheDocument()
    })

    it('renders an honest fallback state when weather is unavailable or in fallback mode', () => {
        const fallbackWeather = {
            status: 'FALLBACK',
            available: false,
            provider: 'OPEN_METEO',
            source_label: 'Open-Meteo',
            location_name: 'Kurnool, Andhra Pradesh, India',
            fallback_explanation:
                'External weather provider unavailable: solar forecast relies on clear-sky physical solar pattern fallback model.',
        }

        render(<WeatherInputCard weather={fallbackWeather} />)

        expect(screen.getByText('Fallback')).toBeInTheDocument()
        expect(screen.getByTestId('weather-fallback-explanation')).toBeInTheDocument()
        expect(
            screen.getByText(/solar forecast relies on clear-sky physical solar pattern fallback model/i)
        ).toBeInTheDocument()
        // Ensure no fabricated weather metrics are displayed
        expect(screen.queryByText('31.5°C')).not.toBeInTheDocument()
    })

    it('renders unavailable status when weather data is missing or location is unconfigured', () => {
        const unavailableWeather = {
            status: 'UNAVAILABLE',
            available: false,
            provider: 'OPEN_METEO',
            source_label: 'Open-Meteo',
            fallback_explanation:
                'Household location is not configured: solar forecast relies on clear-sky physical solar pattern fallback model.',
        }

        render(<WeatherInputCard weather={unavailableWeather} />)

        expect(screen.getByText('Unavailable')).toBeInTheDocument()
        expect(
            screen.getByText(/Household location is not configured: solar forecast relies on clear-sky physical solar pattern fallback model/i)
        ).toBeInTheDocument()
    })

    it('renders loading state when isLoading is true and weather is not yet available', () => {
        render(<WeatherInputCard weather={null} isLoading={true} />)

        expect(screen.getByTestId('weather-card-loading')).toBeInTheDocument()
        expect(screen.getByText(/Loading weather evidence.../i)).toBeInTheDocument()
    })

    it('gracefully handles missing optional weather fields without throwing or fabricating values', () => {
        const partialWeather = {
            status: 'WEATHER_ENHANCED',
            available: true,
            provider: 'OPEN_METEO',
            source_label: 'Open-Meteo',
            location_name: 'Household Location',
            temperature_c: null,
            cloud_cover_pct: null,
            precipitation_probability_pct: null,
            solar_irradiance_wm2: null,
            clear_sky_index: null,
        }

        render(<WeatherInputCard weather={partialWeather} />)

        expect(screen.getByTestId('weather-input-card')).toBeInTheDocument()
        expect(screen.getByText('Weather-enhanced')).toBeInTheDocument()
        // Missing fields show '--'
        const placeholderDashes = screen.getAllByText('--')
        expect(placeholderDashes.length).toBeGreaterThanOrEqual(3)
    })

    it('clearly distinguishes forecast weather data from measured household telemetry', () => {
        render(<WeatherInputCard weather={sampleWeatherData} />)

        expect(
            screen.getByText(
                /Weather data represents predictive atmospheric evidence from Open-Meteo, distinct from measured household telemetry/i
            )
        ).toBeInTheDocument()
    })
})
