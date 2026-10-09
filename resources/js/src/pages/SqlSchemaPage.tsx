import {
  ArrowsClockwise,
  BoundingBox,
  FlowArrow,
  GitCommit,
  LineSegments,
  Plus,
  Selection,
  SidebarSimple,
  Trash,
} from '@phosphor-icons/react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import type { SchemaDrift } from '../api/types'
import { DriftBanner } from '../components/sql/DriftBanner'
import { sqlApi } from '../api/sql'
import { CommitModal } from '../components/sql/CommitModal'
import { useToast } from '../components/ui/Toast'
import { changeMarks } from '../lib/changes'
import { Link, useParams } from 'react-router-dom'
import { EditorAction, EditorHeader, EditorIcon } from '../components/layout/EditorHeader'
import { ExportModal } from '../components/sql/ExportModal'
import { NewTableModal } from '../components/sql/NewTableModal'
import { SchemaSettingsModal } from '../components/sql/SchemaSettingsModal'
import { SchemaSidebar } from '../components/sql/SchemaSidebar'
import { SqlCanvas } from '../components/sql/SqlCanvas'
import { TABLE_WIDTH } from '../components/sql/geometry'
import { TableEditorPanel } from '../components/sql/TableEditorPanel'
import { ZoneCard } from '../components/sql/ZoneCard'
import { defaultTableName } from '../lib/sqlDiagram'
import { cn } from '../lib/cn'
import { useSqlSchema } from '../hooks/useSqlSchema'
import { t, tablesCount } from '../lib/i18n'

export function SqlSchemaPage() {
  const { id } = useParams()
  const schemaId = Number(id)
  const {
    schema,
    diagram,
    loading,
    error,
    saveState,
    addTable,
    updateTable,
    moveTable,
    removeTable,
    addColumn,
    updateColumn,
    removeColumn,
    reorderColumns,
    addRelation,
    removeRelation,
    setCanvas,
    setLineMode,
    addZone,
    updateZone,
    removeZone,
    updateSettings,
    saveNow,
    reload,
  } = useSqlSchema(schemaId)
  const { notify } = useToast()

  // Что изменилось с последнего коммита: счётчик в шапке и пометки на холсте.
  const marks = useMemo(
    () => (diagram ? changeMarks(diagram, schema?.baseline) : null),
    [diagram, schema?.baseline],
  )
  const [commitOpen, setCommitOpen] = useState(false)
  const [pulling, setPulling] = useState(false)
  const [drift, setDrift] = useState<SchemaDrift | null>(null)

  // Схема проекта: не изменилась ли база в обход редактора. Проверяем при
  // открытии и при возврате во вкладку — например, после migrate в терминале.
  const isProject = schema?.source === 'database'
  const checkDrift = useCallback(() => {
    if (!isProject) return
    sqlApi
      .drift(schemaId)
      .then(setDrift)
      .catch(() => setDrift(null))
  }, [isProject, schemaId])
  useEffect(() => {
    checkDrift()
    const onFocus = () => document.visibilityState === 'visible' && checkDrift()
    window.addEventListener('focus', onFocus)
    document.addEventListener('visibilitychange', onFocus)
    return () => {
      window.removeEventListener('focus', onFocus)
      document.removeEventListener('visibilitychange', onFocus)
    }
  }, [checkDrift])

  async function pullFromDatabase() {
    if (marks && marks.count > 0 && !window.confirm(t('pullConfirm'))) return
    setPulling(true)
    try {
      await saveNow()
      await sqlApi.sync(schemaId)
      await reload()
      setDrift(null)
      checkDrift()
    } catch {
      notify(t('pullFailed'))
    } finally {
      setPulling(false)
    }
  }

  const [selectedTable, setSelectedTable] = useState<string | null>(null)
  const [selectedRelation, setSelectedRelation] = useState<string | null>(null)
  const [exportOpen, setExportOpen] = useState(false)
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [newTableOpen, setNewTableOpen] = useState(false)
  const [zoneMode, setZoneMode] = useState(false)
  const [zoneDrawArmed, setZoneDrawArmed] = useState(false)
  const [selectMode, setSelectMode] = useState(false)
  const [selectedZone, setSelectedZone] = useState<string | null>(null)
  // Левую панель можно спрятать, чтобы таблицам было просторнее; выбор помним.
  const [sidebarHidden, setSidebarHidden] = useState(() => {
    try {
      return localStorage.getItem('cheap-sql-scheme-sidebar') === 'hidden'
    } catch {
      return false
    }
  })
  function toggleSidebar() {
    setSidebarHidden((v) => {
      try {
        localStorage.setItem('cheap-sql-scheme-sidebar', v ? 'shown' : 'hidden')
      } catch {
        /* приватный режим — просто не запомним */
      }
      return !v
    })
  }

  const activeTable = diagram?.tables.find((t) => t.uid === selectedTable) ?? null
  const activeZone = diagram?.zones.find((z) => z.uid === selectedZone) ?? null

  // Инструменты «зоны» и «выделение» взаимоисключающие.
  function toggleZoneMode() {
    setZoneMode((v) => {
      const next = !v
      if (next) setSelectMode(false)
      if (!next) {
        setZoneDrawArmed(false)
        setSelectedZone(null)
      }
      return next
    })
  }

  function toggleSelectMode() {
    setSelectMode((v) => {
      const next = !v
      if (next) {
        setZoneMode(false)
        setZoneDrawArmed(false)
      }
      return next
    })
  }

  function createTable(name: string) {
    if (!diagram) return
    const { zoom, offsetX, offsetY } = diagram.canvas
    const x = (window.innerWidth / 2 - offsetX) / zoom - TABLE_WIDTH / 2
    const y = (window.innerHeight / 2 - offsetY) / zoom - 40
    addTable(x, y, name)
  }

  if (loading) {
    return (
      <div className="grid h-full place-items-center text-[13px] text-neutral-500">{t('loading')}</div>
    )
  }
  if (error || !diagram || !schema) {
    return (
      <div className="grid h-full place-items-center gap-3 text-center">
        <p className="text-[13px] text-danger">{error ?? t('schemaNotFound')}</p>
        <Link to="/" className="text-[13px] text-accent hover:underline">
          {t('backToSchemas')}
        </Link>
      </div>
    )
  }

  return (
    <div className="flex h-full flex-col overflow-hidden">
      <EditorHeader
        backTo="/"
        backLabel={t('schemasTitle')}
        title={schema.name}
        meta={
          schema.source === 'database'
            ? `${tablesCount(diagram.tables.length)} · ${t('projectBadge')}`
            : tablesCount(diagram.tables.length)
        }
        save={saveState}
        compact
      >
        {schema.source === 'database' && (
          <EditorAction
            icon={<ArrowsClockwise size={18} className={pulling ? 'animate-spin' : undefined} />}
            label={t('pullFromDb')}
            onClick={() => void pullFromDatabase()}
          />
        )}
        <EditorAction
          icon={<GitCommit size={18} />}
          label={marks?.count ? `${t('changesAction')} · ${marks.count}` : t('changesAction')}
          pressed={Boolean(marks?.count && schema.baseline)}
          onClick={() => setCommitOpen(true)}
        />
        <EditorAction
          icon={EditorIcon.export}
          label={t('exportAction')}
          onClick={() => setExportOpen(true)}
        />
        <EditorAction
          icon={EditorIcon.gear}
          label={t('settingsAction')}
          onClick={() => setSettingsOpen(true)}
        />
      </EditorHeader>

      <div className="relative flex min-h-0 flex-1">
        {/* Редактор таблицы открывается и при спрятанной панели — по клику на таблицу. */}
        {activeTable ? (
          <TableEditorPanel
            table={activeTable}
            allTables={diagram.tables}
            relations={diagram.relations}
            onBack={() => setSelectedTable(null)}
            onUpdateTable={(patch) => updateTable(activeTable.uid, patch)}
            onRemoveTable={() => {
              removeTable(activeTable.uid)
              setSelectedTable(null)
            }}
            onAddColumn={() => addColumn(activeTable.uid)}
            onUpdateColumn={(columnUid, patch) => updateColumn(activeTable.uid, columnUid, patch)}
            onRemoveColumn={(columnUid) => removeColumn(activeTable.uid, columnUid)}
            onReorderColumns={(from, to) => reorderColumns(activeTable.uid, from, to)}
            onAddRelation={addRelation}
            onRemoveRelation={removeRelation}
            onRequestNewTable={() => setNewTableOpen(true)}
          />
        ) : sidebarHidden ? null : (
          <SchemaSidebar
            schemaName={schema.name}
            tables={diagram.tables}
            selectedTable={selectedTable}
            onOpenSettings={() => setSettingsOpen(true)}
            onSelectTable={setSelectedTable}
            onAddTable={() => setNewTableOpen(true)}
            onRemoveTable={removeTable}
            onExport={() => setExportOpen(true)}
          />
        )}

        <div className="relative min-w-0 flex-1">
          <SqlCanvas
            diagram={diagram}
            selectedTable={selectedTable}
            selectedRelation={selectedRelation}
            zoneMode={zoneMode}
            zoneDrawArmed={zoneDrawArmed}
            selectMode={selectMode}
            onSelectTable={(uid) => {
              setSelectedTable(uid)
              setSelectedRelation(null)
            }}
            onSelectRelation={(uid) => {
              setSelectedRelation(uid)
              if (uid) setSelectedTable(null)
            }}
            onMoveTable={moveTable}
            onAddRelation={addRelation}
            onCanvasChange={setCanvas}
            onAddZone={addZone}
            onUpdateZone={updateZone}
            selectedZone={zoneMode ? selectedZone : null}
            onSelectZone={setSelectedZone}
            onZoneDrawn={() => setZoneDrawArmed(false)}
            changeMarks={marks?.tables}
          />

          {/* Панель инструментов холста: режим линий и режим зон. */}
          <div className="absolute top-3 left-3 flex items-center gap-2">
            <button
              onClick={toggleSidebar}
              title={sidebarHidden ? t('showSidebar') : t('hideSidebar')}
              aria-label={sidebarHidden ? t('showSidebar') : t('hideSidebar')}
              aria-pressed={!sidebarHidden}
              className="grid size-[34px] place-items-center rounded-lg bg-surface text-neutral-300 shadow-[var(--shadow-sm)] transition-colors hover:text-accent"
            >
              <SidebarSimple size={16} weight={sidebarHidden ? 'regular' : 'fill'} />
            </button>
            <div className="seg bg-surface shadow-[var(--shadow-sm)]">
              <button
                className="seg-opt"
                aria-selected={diagram.lineMode === 'smooth'}
                onClick={() => setLineMode('smooth')}
                title={t('smoothTitle')}
              >
                <FlowArrow size={14} /> {t('smooth')}
              </button>
              <button
                className="seg-opt"
                aria-selected={diagram.lineMode === 'straight'}
                onClick={() => setLineMode('straight')}
                title={t('straightTitle')}
              >
                <LineSegments size={14} /> {t('straight')}
              </button>
            </div>

            <button
              onClick={toggleSelectMode}
              title={t('selectTitle')}
              className={cn(
                'flex items-center gap-1.5 rounded-lg px-3 py-2 text-[12px] shadow-[var(--shadow-sm)] transition-colors',
                selectMode
                  ? 'bg-accent text-accent-fg'
                  : 'bg-surface text-neutral-300 hover:text-accent',
              )}
            >
              <BoundingBox size={14} /> {t('select')}
            </button>

            <button
              onClick={toggleZoneMode}
              title={zoneMode ? t('zonesExit') : t('zonesEnter')}
              className={cn(
                'flex items-center gap-1.5 rounded-lg px-3 py-2 text-[12px] shadow-[var(--shadow-sm)] transition-colors',
                zoneMode
                  ? 'bg-accent text-accent-fg'
                  : 'bg-surface text-neutral-300 hover:text-accent',
              )}
            >
              <Selection size={14} /> {t('zones')}
            </button>

            {zoneMode && (
              <button
                onClick={() => setZoneDrawArmed(true)}
                title={t('drawZoneTitle')}
                className={cn(
                  'flex items-center gap-1.5 rounded-lg px-3 py-2 text-[12px] shadow-[var(--shadow-sm)] transition-colors',
                  zoneDrawArmed
                    ? 'bg-accent text-accent-fg'
                    : 'bg-surface text-accent hover:bg-accent/10',
                )}
              >
                <Plus size={14} /> {t('createZone')}
              </button>
            )}
          </div>

          {zoneMode && activeZone && (
            <ZoneCard
              zone={activeZone}
              onUpdate={(patch) => updateZone(activeZone.uid, patch)}
              onRemove={() => {
                removeZone(activeZone.uid)
                setSelectedZone(null)
              }}
              onClose={() => setSelectedZone(null)}
            />
          )}

          {zoneMode && zoneDrawArmed && (
            <div className="pointer-events-none absolute top-3 left-1/2 -translate-x-1/2 rounded-full bg-surface px-3 py-1.5 text-[11.5px] text-neutral-400 shadow-[var(--shadow-sm)]">
              {t('drawZoneHint')}
            </div>
          )}

          {drift?.changed && (
            <DriftBanner
              drift={drift}
              refreshing={pulling}
              onRefresh={() => void pullFromDatabase()}
            />
          )}

          {selectMode && (
            <div className="pointer-events-none absolute top-3 left-1/2 -translate-x-1/2 rounded-full bg-surface px-3 py-1.5 text-[11.5px] text-neutral-400 shadow-[var(--shadow-sm)]">
              {t('selectHint')}
            </div>
          )}

          {selectedRelation && (
            <button
              onClick={() => {
                removeRelation(selectedRelation)
                setSelectedRelation(null)
              }}
              className="absolute bottom-3 left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-surface px-3 py-1.5 text-[12px] text-danger shadow-[var(--shadow-md)] transition-colors hover:bg-danger/10"
            >
              <Trash size={13} /> {t('deleteRelation')}
            </button>
          )}

          {diagram.tables.length === 0 && (
            <div className="pointer-events-none absolute inset-0 grid place-items-center">
              <p className="max-w-[300px] text-center text-[13px] leading-[1.6] text-neutral-500">
                {t('emptyCanvas')}
              </p>
            </div>
          )}
        </div>
      </div>

      <ExportModal open={exportOpen} schemaId={schemaId} onClose={() => setExportOpen(false)} />

      <CommitModal
        open={commitOpen}
        schemaId={schemaId}
        beforeLoad={saveNow}
        onClose={() => setCommitOpen(false)}
        onCommitted={() => {
          void reload()
          checkDrift()
        }}
      />

      <SchemaSettingsModal
        open={settingsOpen}
        name={schema.name}
        onClose={() => setSettingsOpen(false)}
        onSave={updateSettings}
      />

      <NewTableModal
        open={newTableOpen}
        defaultName={defaultTableName(diagram.tables)}
        onClose={() => setNewTableOpen(false)}
        onCreate={createTable}
      />
    </div>
  )
}
