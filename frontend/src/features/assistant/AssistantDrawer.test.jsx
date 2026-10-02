import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { AssistantDrawer } from './AssistantDrawer'
import { AuthContext } from '../auth/AuthContext'

function renderDrawer(props = {}, authValue = {}) {
    const defaultAuth = {
        account: { email: 'user@example.com', role: 'OWNER', mode: 'REAL', environment: 'REAL' },
        isAuthenticated: true,
        status: 'authenticated',
        logout: vi.fn(),
        ...authValue,
    }

    const defaultProps = {
        isOpen: true,
        onClose: vi.fn(),
        messages: [],
        loading: false,
        error: null,
        onSendMessage: vi.fn(),
        onRetry: vi.fn(),
        onClear: vi.fn(),
        pageContext: 'forecasts',
        ...props,
    }

    return {
        ...render(
            <AuthContext.Provider value={defaultAuth}>
                <AssistantDrawer {...defaultProps} />
            </AuthContext.Provider>
        ),
        props: defaultProps,
    }
}

describe('F8 — AssistantDrawer Component', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('does not render when isOpen is false', () => {
        renderDrawer({ isOpen: false })
        expect(screen.queryByText('SolarAI')).not.toBeInTheDocument()
    })

    it('renders header, title, and pageContext when isOpen is true', () => {
        renderDrawer({ isOpen: true, pageContext: 'forecasts' })
        expect(screen.getByText('SolarAI')).toBeInTheDocument()
        expect(screen.getByText('Forecasts')).toBeInTheDocument()
        expect(screen.getByText('REAL DATA')).toBeInTheDocument()
    })

    it('displays DEMO badge when account is in DEMO mode', () => {
        renderDrawer(
            { isOpen: true },
            { account: { email: 'demo@spms.local', role: 'OWNER', mode: 'DEMO_TEST', environment: 'DEMO' } }
        )
        expect(screen.getByText('DEMO SIMULATION')).toBeInTheDocument()
    })

    it('shows welcome guidance and suggested questions when conversation is empty', () => {
        const onSendMessage = vi.fn()
        renderDrawer({ messages: [], onSendMessage })

        expect(screen.getByText("Hi, I'm SolarAI")).toBeInTheDocument()
        expect(screen.getByText('Suggested Questions')).toBeInTheDocument()

        const surplusButton = screen.getByText('How accurate is my forecast?')
        expect(surplusButton).toBeInTheDocument()

        fireEvent.click(surplusButton)
        expect(onSendMessage).toHaveBeenCalledWith('How accurate is my forecast?')
    })

    it('renders conversation messages with metadata badges and limitations', () => {
        const messages = [
            {
                id: '1',
                role: 'user',
                content: 'What is my current forecast?',
                createdAt: new Date().toISOString(),
            },
            {
                id: '2',
                role: 'assistant',
                content: 'Your day-ahead forecast shows 16.292 kWh solar and 2.581 kWh consumption.',
                provider: 'gemini',
                model: 'gemini-1.5-flash',
                dataNature: 'PREDICTED',
                confidence: 'MODERATE',
                limitations: ['Solar telemetry delay of 12 minutes'],
                fallbackUsed: false,
                createdAt: new Date().toISOString(),
            },
        ]

        renderDrawer({ messages })

        expect(screen.getByText('What is my current forecast?')).toBeInTheDocument()
        expect(
            screen.getByText('Your day-ahead forecast shows 16.292 kWh solar and 2.581 kWh consumption.')
        ).toBeInTheDocument()
        expect(screen.getByText('PREDICTED')).toBeInTheDocument()
        expect(screen.getByText('Confidence: MODERATE')).toBeInTheDocument()
        expect(screen.getByText('gemini')).toBeInTheDocument()
        expect(screen.getByText('Solar telemetry delay of 12 minutes')).toBeInTheDocument()
    })

    it('renders loading state with animated indicator', () => {
        renderDrawer({ loading: true })
        expect(
            screen.getByText(/SolarAI is reading your household data/i)
        ).toBeInTheDocument()
    })

    it('renders error state and triggers retry', () => {
        const onRetry = vi.fn()
        renderDrawer({ error: 'Failed to contact provider.', onRetry })

        expect(screen.getByText('Failed to contact provider.')).toBeInTheDocument()
        const retryButton = screen.getByRole('button', { name: /retry/i })
        fireEvent.click(retryButton)
        expect(onRetry).toHaveBeenCalled()
    })

    it('handles typing and submitting a query', () => {
        const onSendMessage = vi.fn()
        renderDrawer({ onSendMessage })

        const input = screen.getByPlaceholderText(/Ask about solar, forecasts, bills, or recommendations/i)
        fireEvent.change(input, { target: { value: 'Explain my latest bill' } })

        const sendButton = screen.getByRole('button', { name: /send message/i })
        fireEvent.click(sendButton)

        expect(onSendMessage).toHaveBeenCalledWith('Explain my latest bill')
    })

    it('calls onClear when clear button is clicked', () => {
        const onClear = vi.fn()
        renderDrawer({
            messages: [{ id: '1', role: 'user', content: 'Hi' }],
            onClear,
        })

        const clearButton = screen.getByRole('button', { name: /clear conversation/i })
        fireEvent.click(clearButton)
        expect(onClear).toHaveBeenCalled()
    })
})
