import { useEffect, useState } from 'react'
import { ApiError } from '../../api/client'
import { Button } from '../ui/Button'
import { Input } from '../ui/Input'
import { Modal } from '../ui/Modal'
import { t } from '../../lib/i18n'

interface SchemaSettingsModalProps {
  open: boolean
  name: string
  onClose: () => void
  onSave: (input: { name: string }) => Promise<void>
}

/** Настройки схемы: название. */
export function SchemaSettingsModal({ open, name, onClose, onSave }: SchemaSettingsModalProps) {
  const [draftName, setDraftName] = useState(name)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Сбрасываем черновик при каждом открытии.
  useEffect(() => {
    if (open) {
      setDraftName(name)
      setError(null)
    }
  }, [open, name])

  async function submit() {
    if (!draftName.trim()) {
      setError(t('enterName'))
      return
    }
    setBusy(true)
    setError(null)
    try {
      await onSave({ name: draftName.trim() })
      onClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t('saveSettingsFailed'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open={open}
      title={t('schemaSettings')}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('cancel')}
          </Button>
          <Button onClick={submit} loading={busy}>
            {t('save')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Input
          label={t('nameLabel')}
          value={draftName}
          onChange={(e) => setDraftName(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && void submit()}
          placeholder={t('schemaNamePlaceholder')}
          autoFocus
        />

        {error && <p className="text-[12.5px] text-danger">{error}</p>}
      </div>
    </Modal>
  )
}
