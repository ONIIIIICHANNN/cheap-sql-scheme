import { useEffect, useState } from 'react'
import { Button } from '../ui/Button'
import { Input } from '../ui/Input'
import { Modal } from '../ui/Modal'

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
      title="Новая таблица"
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Отмена
          </Button>
          <Button onClick={submit}>Создать</Button>
        </>
      }
    >
      <Input
        label="Имя таблицы"
        value={name}
        onChange={(e) => setName(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') submit()
        }}
        placeholder="Например, users"
        autoFocus
      />
    </Modal>
  )
}
