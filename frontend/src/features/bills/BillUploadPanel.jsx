import { useRef, useState } from 'react'
import { FileCheck2, TriangleAlert, UploadCloud, X } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { ACCEPTED_UPLOAD_ATTR, clientSideUploadCheck, formatBytes } from './presentation'
import { cn } from '../../lib/utils'

/**
 * BillUploadPanel — the workspace entry point (B4 §18/§19).
 *
 * Frontend validation is deliberately EARLY-UX only: an obvious type or
 * size problem is surfaced immediately, but the server (B2 fail-closed
 * intake: signature-checked bytes, real size, one-part multipart) remains
 * the sole authority — no client check ever green-lights a file. Only the
 * locally selected File object is kept (never browser storage, never the
 * bytes), and just its name/size are shown.
 */
export function BillUploadPanel({ onUpload, busy = false, externalError = null, collapsed = false }) {
    const inputRef = useRef(null)
    const [file, setFile] = useState(null)
    const [localError, setError] = useState(null)
    const [dragActive, setDragActive] = useState(false)
    const [uploading, setUploading] = useState(false)

    const accept = async (candidate) => {
        if (busy || uploading) {
            return
        }
        const problem = clientSideUploadCheck(candidate)
        if (problem) {
            setError(problem)
            setFile(null)
            return
        }
        setError(null)
        setFile(candidate)
    }

    const openPicker = () => inputRef.current?.click()

    const submit = async () => {
        if (!file || uploading || busy) {
            return
        }
        setUploading(true)
        const outcome = (await onUpload(file)) ?? { uploaded: true }
        setUploading(false)
        if (outcome.uploaded) {
            setFile(null)
            setError(null)
        } else if (outcome.error) {
            setError(outcome.error)
        }
    }

    const error = localError ?? externalError

    if (collapsed) {
        return null
    }

    return (
        <Card aria-labelledby="bill-upload-title">
            <CardHeader>
                <CardTitle id="bill-upload-title" className="flex items-center gap-2">
                    <UploadCloud className="size-5 text-secondary" aria-hidden="true" />
                    Upload your electricity bill
                </CardTitle>
                <CardDescription>PDF, JPG or PNG up to 25 MB. The bill stays private to your household.</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <div
                    onDragOver={(event) => {
                        event.preventDefault()
                        setDragActive(true)
                    }}
                    onDragLeave={() => setDragActive(false)}
                    onDrop={(event) => {
                        event.preventDefault()
                        setDragActive(false)
                        const dropped = event.dataTransfer?.files?.[0]
                        if (dropped) {
                            void accept(dropped)
                        }
                    }}
                    className={cn(
                        'flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed px-6 py-8 text-center transition-colors duration-150',
                        dragActive ? 'border-primary bg-primary-soft/50' : 'border-border bg-surface-alt/50',
                    )}
                >
                    <UploadCloud className="size-6 text-muted" aria-hidden="true" />
                    <p className="text-sm text-body">
                        Drag your bill here, or
                    </p>
                    <Button type="button" variant="secondary" onClick={openPicker} disabled={busy || uploading}>
                        Choose file
                    </Button>
                    <input
                        ref={inputRef}
                        type="file"
                        accept={ACCEPTED_UPLOAD_ATTR}
                        className="sr-only"
                        aria-label="Choose a bill file to upload"
                        aria-describedby={error ? 'bill-upload-error' : 'bill-upload-hint'}
                        onChange={(event) => {
                            const selected = event.target.files?.[0]
                            if (selected) {
                                void accept(selected)
                            }
                            event.target.value = ''
                        }}
                    />
                    <p id="bill-upload-hint" className="text-xs text-muted">
                        Your file is checked by the server — this screen only confirms it arrived.
                    </p>
                </div>

                {file && (
                    <div className="flex items-center justify-between gap-3 rounded-md border border-border bg-surface px-4 py-3">
                        <p className="flex min-w-0 items-center gap-2 text-sm text-body">
                            <FileCheck2 className="size-4 shrink-0 text-primary" aria-hidden="true" />
                            <span className="truncate font-medium">{file.name}</span>
                            <span className="shrink-0 text-xs text-muted">{formatBytes(file.size)}</span>
                        </p>
                        <Button
                            type="button"
                            variant="tertiary"
                            size="sm"
                            onClick={() => setFile(null)}
                            disabled={uploading}
                            aria-label={`Remove ${file.name} from the upload`}
                        >
                            <X className="size-3.5" aria-hidden="true" />
                            Remove
                        </Button>
                    </div>
                )}

                {error && (
                    <p
                        id="bill-upload-error"
                        role="alert"
                        className="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-warning"
                    >
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        {error}
                    </p>
                )}

                <div>
                    <Button type="button" onClick={submit} loading={uploading} disabled={!file || busy}>
                        Upload bill
                    </Button>
                </div>
            </CardContent>
        </Card>
    )
}
