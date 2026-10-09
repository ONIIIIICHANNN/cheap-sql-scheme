import { useEffect, useState } from 'react'
import { Button } from '../ui/Button'
import { Input } from '../ui/Input'
import { Modal } from '../ui/Modal'
import { t } from '../../lib/i18n'

interface NewTableModalProps {
  open: boolean
  defaultName: string
  onClose: () => void
  onCreate: (name: string) => void
}

/**
 * Модалка создания таблицы. Имя предзаполнено разумным значением по
 * умолчанию — можно сразу нажать «Создать» и продолжить работу.
 */
export function NewTableModal({ open, defaultName, onClose, onCreate }: NewTableModalProps) {
  const [name, setName] = useState(defaultName)

  useEffect(() => {
    if (open) setName(defaultName)
  }, [open, defaultName])

  function submit() {
    onCreate(name.trim() || defaultName)
    onClose()
  }

  return (
    <Modal
      open={open}
      title={t('newTableTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('cancel')}
          </Button>
          <Button onClick={submit}>{t('create')}</Button>
        </>
      }
    >
      <Input
        label={t('tableName')}
        value={name}
        onChange={(e) => setName(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') submit()
        }}
        placeholder={t('tableNamePlaceholder')}
        autoFocus
      />
    </Modal>
  )
}
