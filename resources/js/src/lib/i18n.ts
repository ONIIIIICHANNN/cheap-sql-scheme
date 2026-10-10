import { config } from './config'

/**
 * Переводы интерфейса. Язык задаёт Laravel (config cheap-sql-scheme.locale) и
 * на странице он не меняется, поэтому хватает обычной функции t().
 * Подстановки — {name}.
 */
const en = {
  // общее
  cancel: 'Cancel',
  create: 'Create',
  save: 'Save',
  close: 'Close',
  delete: 'Delete',
  confirmDelete: 'Delete for sure?',
  clickAgainToDelete: 'Click again to delete',
  more: 'More',
  back: 'Back',
  backTo: 'Back: {label}',
  undo: 'Undo',
  hide: 'Hide',
  loading: 'Loading…',
  nameLabel: 'Name',
  enterName: 'Enter a name',
  themeLight: 'Light theme',
  themeDark: 'Dark theme',

  // ошибки запросов
  requestFailed: 'Request failed ({status})',
  loadSchemasFailed: 'Could not load schemas',
  loadSchemaFailed: 'Could not load the schema',
  saveSettingsFailed: 'Could not save settings',
  createSchemaFailed: 'Could not create the schema',
  buildZipFailed: 'Could not build the archive',
  buildSqlFailed: 'Could not build the .sql file',
  buildMigrationFailed: 'Could not build the migration',

  // сохранение
  saving: 'Saving…',
  saved: 'Saved',
  notSaved: 'Not saved',

  // список схем
  schemasTitle: 'SQL schemas',
  schemasSubtitle: 'Tables, keys and relations on a canvas. Export a finished schema as Laravel migrations.',
  noSchemasTitle: 'No schemas yet',
  noSchemasText:
    'Design your database on a canvas: tables, columns, keys and relations, then export it as Laravel migrations. Or start from an existing SQL file.',
  createSchema: 'Create schema',
  totalSchemas: 'Schemas',
  totalTables: 'Tables',
  newSchema: 'New schema',
  newSchemaHint: 'Empty or from an SQL file',
  freshSticker: 'new!',
  emptyCover: 'empty so far',
  schemaDeleted: '“{name}” deleted',

  // форма новой схемы
  newSchemaTitle: 'New database schema',
  createFromFile: 'Create from file',
  schemaNamePlaceholder: 'e.g. Online store',
  importOptional: 'Import from SQL (optional)',
  removeFile: 'Remove file',
  pickFile: 'Choose a .sql or migration file',
  importHint: 'Tables from the file will be laid out on the canvas automatically.',

  // редактор
  schemaNotFound: 'Schema not found',
  backToSchemas: '← Back to schemas',
  settingsAction: 'Settings',
  showSidebar: 'Show tables panel',
  hideSidebar: 'Hide tables panel',
  smoothTitle: 'Smooth curves',
  smooth: 'Smooth',
  straightTitle: 'Strict right angles',
  straight: 'Straight',
  selectTitle: 'Selection — drag a frame around tables to move them together',
  select: 'Select',
  zonesEnter: 'Zones mode',
  zonesExit: 'Leave zones mode',
  zones: 'Zones',
  drawZoneTitle: 'Draw a zone on the canvas',
  createZone: 'Create zone',
  drawZoneHint: 'Draw a zone on the canvas with the mouse',
  selectHint: 'Frame the tables, then drag any of the selected ones',
  deleteRelation: 'Delete relation',
  emptyCanvas: 'Empty canvas. Click “Add table” in the left panel, then drag a relation from column to column.',

  // боковая панель
  database: 'Database',
  tablesHeading: 'Tables',
  addTable: 'Add table',
  noTablesYet: 'Nothing here yet. Add a table and it will appear on the canvas.',
  confirmDeleteTable: 'Delete table “{name}”?',
  deleteTable: 'Delete table',

  // таблица и колонки
  newTableTitle: 'New table',
  tableName: 'Table name',
  tableNamePlaceholder: 'e.g. users',
  backToTables: 'Back to tables',
  columnName: 'Column name',
  columnType: 'Column type',
  deleteColumn: 'Delete column',
  primaryKeyLocked: 'The primary key cannot be deleted',
  foreignKeyToggle: 'Foreign key (FK)',
  chooseColumn: 'choose a column',
  change: 'change',
  addColumn: 'Column',
  linkToColumn: 'Link to a column',

  // внешний ключ
  foreignKeyTitle: 'Foreign key',
  createTable: 'Create table',
  chooseColumnHint: 'Choose a column',
  link: 'Link',
  noOtherTables: 'There is no other table to reference yet.',
  noOtherTablesHint: 'Create one, then pick a column for the relation.',
  willReference: 'Column {column} will reference:',
  searchPlaceholder: 'Table or column',
  searchLabel: 'Search tables or columns',
  sortSmart: 'Best match',
  sortAlpha: 'A–Z',
  keysOnlyTitle: 'Show only primary and unique columns',
  keysOnly: 'Keys only',
  nothingFound: 'Nothing found.',
  tryKeysOff: ' Try turning off “Keys only”.',
  nameMatch: 'Matches by name',
  doubleClickLink: 'Double-click to link right away',
  keyLabel: 'Key',

  // зоны
  zone: 'Zone',
  zoneNamePlaceholder: 'e.g. Payments',
  color: 'Color',
  colorValue: 'Color {color}',
  deleteZone: 'Delete zone',
  zoneDragHint: 'Drag to move. Name and color are in the “Zone” card',

  // настройки
  schemaSettings: 'Schema settings',

  // экспорт
  copy: 'Copy',
  copied: 'Copied',
  downloadSql: 'Download .sql',
  downloadPhp: 'Download .php',
  downloadZip: 'Download .zip',
  zipping: 'Packing…',
  modeSingle: 'Laravel migration',
  modeFiles: 'One file per table',
  modeSql: 'SQL file',
  databaseLabel: 'Database:',
  buildingSql: 'Building .sql…',
  buildingMigrations: 'Building migrations…',
  filesHint:
    'One migration per table. Ordered by relations: a referenced table is created first. Circular relations go into the last file. The archive contains a migrations folder — put its contents into database/migrations.',

  // миграции: коммит и экспорт
  migrationsAction: 'Migrations',
  migrationsTitle: 'Migrations',
  tabCommit: 'Changes',
  tabFullSchema: 'Full schema',
  fullSchemaHint:
    'The whole schema as it is now — for a new project or another database. To update this project, commit the changes instead: a full schema would create existing tables again.',
  statusAdded: 'added',
  statusRemoved: 'removed',
  statusChanged: 'changed',
  statusRenamed: 'renamed',
  changesLoading: 'Comparing with the last commit…',
  changesFailed: 'Could not compare the schema',
  commitFailed: 'Could not commit',
  tabChanges: 'What changed',
  tabMigration: 'Migration code',
  migrationFilesCount: '{count} migration {files}',
  migrationFilesHint: 'One file per table, in the order they run: a table is created before anything references it, dropped tables go last.',
  lastCommit: 'last commit {when}',
  neverCommitted: 'never committed — everything is new',
  noChangesTitle: 'Nothing to commit',
  noChangesText: 'The canvas matches the last commit. Change tables, columns or relations — and they will show up here.',
  pendingTitle: 'Migrations not run yet: {n}',
  pendingHint: 'Run php artisan migrate so the database catches up with the code.',
  commitDownload: 'Commit & download',
  commitDownloadHint: 'Download the migrations and mark these changes as committed',
  otherProjectNote:
    'This schema is not this project’s database, so its migrations are not written here — download them and put them into database/migrations of the project they are for.',
  writeDisabledNote:
    'Writing into the project is turned off outside the local environment. Download the migrations and add them to the project yourself, or set CHEAP_SQL_SCHEME_SAVE_MIGRATIONS=true.',
  commitWrite: 'Commit to project',
  committedTitle: 'Committed',
  committedWritten: 'Saved to {path}. Apply them with:',
  committedDownloaded: 'Downloaded — put the files into database/migrations and run:',
  dataLossWarning: 'Removed tables and columns are dropped with their data.',
  requiredWarning: 'Required (not nullable) and without a default: {columns}. The migration fails if these tables already have rows — make them nullable or give them a default.',
  markAdded: 'New since the last commit',
  markChanged: 'Changed since the last commit',
  pullFromDb: 'Refresh from database',
  driftTitle: 'The database changed outside the editor',
  driftDetails: 'Details',
  driftRefresh: 'Refresh',
  driftModalTitle: 'Changes in the database',
  driftModalHint:
    'These changes were made to the database outside the editor — by migrations or by hand. Refresh to bring the canvas in line with the database.',
  pullConfirm: 'Replace the canvas with the current database structure? Uncommitted changes will be lost.',
  pullFailed: 'Could not read the database',
  projectBadge: 'project database',

  // даты
  today: 'today',
  yesterday: 'yesterday',
  daysAgo: '{n} days ago',
  weekAgo: 'a week ago',
  weeksAgo: '{n} weeks ago',
  monthsAgo: '{n} months ago',
}

export type MessageKey = keyof typeof en

const ru: Record<MessageKey, string> = {
  cancel: 'Отмена',
  create: 'Создать',
  save: 'Сохранить',
  close: 'Закрыть',
  delete: 'Удалить',
  confirmDelete: 'Точно удалить?',
  clickAgainToDelete: 'Нажмите ещё раз, чтобы удалить',
  more: 'Ещё',
  back: 'Назад',
  backTo: 'Назад: {label}',
  undo: 'Вернуть',
  hide: 'Скрыть',
  loading: 'Загрузка…',
  nameLabel: 'Название',
  enterName: 'Введите название',
  themeLight: 'Светлая тема',
  themeDark: 'Тёмная тема',

  requestFailed: 'Ошибка запроса ({status})',
  loadSchemasFailed: 'Не удалось загрузить схемы',
  loadSchemaFailed: 'Не удалось загрузить схему',
  saveSettingsFailed: 'Не удалось сохранить настройки',
  createSchemaFailed: 'Не удалось создать схему',
  buildZipFailed: 'Не удалось собрать архив',
  buildSqlFailed: 'Не удалось собрать .sql',
  buildMigrationFailed: 'Не удалось собрать миграцию',

  saving: 'Сохранение…',
  saved: 'Сохранено',
  notSaved: 'Не сохранилось',

  schemasTitle: 'SQL-схемы',
  schemasSubtitle: 'Таблицы, ключи и связи на холсте. Готовую схему можно выгрузить в миграцию Laravel.',
  noSchemasTitle: 'Схем пока нет',
  noSchemasText:
    'Спроектируйте базу на холсте: таблицы, колонки, ключи и связи, затем выгрузите в миграцию Laravel. Или начните с готового SQL-файла.',
  createSchema: 'Создать схему',
  totalSchemas: 'Всего схем',
  totalTables: 'Таблиц',
  newSchema: 'Новая схема',
  newSchemaHint: 'Пустая или из SQL-файла',
  freshSticker: 'новая!',
  emptyCover: 'пока пусто',
  schemaDeleted: '«{name}» удалена',

  newSchemaTitle: 'Новая схема БД',
  createFromFile: 'Создать из файла',
  schemaNamePlaceholder: 'Например, Интернет-магазин',
  importOptional: 'Импорт из SQL (необязательно)',
  removeFile: 'Убрать файл',
  pickFile: 'Выбрать .sql или файл миграции',
  importHint: 'Таблицы из файла разложатся по холсту автоматически.',

  schemaNotFound: 'Схема не найдена',
  backToSchemas: '← К списку схем',
  settingsAction: 'Настройки',
  showSidebar: 'Показать панель таблиц',
  hideSidebar: 'Спрятать панель таблиц',
  smoothTitle: 'Мягкие кривые',
  smooth: 'Мягкие',
  straightTitle: 'Строгие прямые',
  straight: 'Прямые',
  selectTitle: 'Выделение — рамкой выберите несколько таблиц и двигайте их вместе',
  select: 'Выделение',
  zonesEnter: 'Режим зон',
  zonesExit: 'Выйти из режима зон',
  zones: 'Зоны',
  drawZoneTitle: 'Начертите зону по холсту',
  createZone: 'Создать зону',
  drawZoneHint: 'Начертите зону мышью по холсту',
  selectHint: 'Обведите таблицы рамкой, затем тяните любую из выделенных',
  deleteRelation: 'Удалить связь',
  emptyCanvas:
    'Пустой холст. Нажмите «Добавить таблицу» в панели слева, затем тяните связь от колонки к колонке.',

  database: 'База данных',
  tablesHeading: 'Таблицы',
  addTable: 'Добавить таблицу',
  noTablesYet: 'Пока пусто. Добавьте таблицу — она появится на холсте.',
  confirmDeleteTable: 'Удалить таблицу «{name}»?',
  deleteTable: 'Удалить таблицу',

  newTableTitle: 'Новая таблица',
  tableName: 'Имя таблицы',
  tableNamePlaceholder: 'Например, users',
  backToTables: 'К списку таблиц',
  columnName: 'Имя колонки',
  columnType: 'Тип колонки',
  deleteColumn: 'Удалить колонку',
  primaryKeyLocked: 'Первичный ключ нельзя удалить',
  foreignKeyToggle: 'Внешний ключ (FK)',
  chooseColumn: 'выбрать колонку',
  change: 'изменить',
  addColumn: 'Колонка',
  linkToColumn: 'Связать с колонкой',

  foreignKeyTitle: 'Внешний ключ',
  createTable: 'Создать таблицу',
  chooseColumnHint: 'Выберите колонку',
  link: 'Связать',
  noOtherTables: 'Пока нет другой таблицы, на которую можно сослаться.',
  noOtherTablesHint: 'Создайте её — затем выберите колонку для связи.',
  willReference: 'Колонка {column} будет ссылаться на:',
  searchPlaceholder: 'Таблица или колонка',
  searchLabel: 'Поиск таблицы или колонки',
  sortSmart: 'Подходящие',
  sortAlpha: 'А–Я',
  keysOnlyTitle: 'Показывать только первичные и уникальные колонки',
  keysOnly: 'Только ключи',
  nothingFound: 'Ничего не нашлось.',
  tryKeysOff: ' Попробуйте выключить «Только ключи».',
  nameMatch: 'Подходит по имени',
  doubleClickLink: 'Двойной клик — связать сразу',
  keyLabel: 'Ключ',

  zone: 'Зона',
  zoneNamePlaceholder: 'Например, Платежи',
  color: 'Цвет',
  colorValue: 'Цвет {color}',
  deleteZone: 'Удалить зону',
  zoneDragHint: 'Тяните, чтобы двигать. Имя и цвет — в карточке «Зона»',

  schemaSettings: 'Настройки схемы',

  copy: 'Копировать',
  copied: 'Скопировано',
  downloadSql: 'Скачать .sql',
  downloadPhp: 'Скачать .php',
  downloadZip: 'Скачать .zip',
  zipping: 'Собираем…',
  modeSingle: 'Миграция Laravel',
  modeFiles: 'Файлами по таблицам',
  modeSql: 'SQL-файл',
  databaseLabel: 'База:',
  buildingSql: 'Собираем .sql…',
  buildingMigrations: 'Собираем миграции…',
  filesHint:
    'По миграции на таблицу. Порядок — по связям: таблица, на которую ссылаются, создаётся раньше. Связи по кругу вынесены в последний файл. В архиве — папка migrations, её содержимое кладётся в database/migrations.',

  statusAdded: 'добавлено',
  statusRemoved: 'удалено',
  statusChanged: 'изменено',
  statusRenamed: 'переименовано',
  migrationsAction: 'Миграции',
  migrationsTitle: 'Миграции',
  tabCommit: 'Изменения',
  tabFullSchema: 'Вся схема',
  fullSchemaHint:
    'Вся схема как есть — для нового проекта или другой базы. Чтобы обновить этот проект, закоммитьте изменения: вся схема создала бы существующие таблицы заново.',
  changesLoading: 'Сравниваем с последним коммитом…',
  changesFailed: 'Не удалось сравнить схему',
  commitFailed: 'Не удалось сделать коммит',
  tabChanges: 'Что изменилось',
  tabMigration: 'Код миграции',
  migrationFilesCount: '{count} {files} миграции',
  migrationFilesHint: 'По файлу на таблицу, в порядке выполнения: таблица создаётся раньше, чем на неё сошлются, удаления — в конце.',
  lastCommit: 'последний коммит {when}',
  neverCommitted: 'коммитов ещё не было — всё новое',
  noChangesTitle: 'Коммитить нечего',
  noChangesText: 'Холст совпадает с последним коммитом. Поменяйте таблицы, колонки или связи — они появятся здесь.',
  pendingTitle: 'Не выполнено миграций: {n}',
  pendingHint: 'Выполните php artisan migrate, чтобы база догнала код.',
  commitDownload: 'Коммит и скачать',
  commitDownloadHint: 'Скачать миграции и отметить эти изменения как закоммиченные',
  otherProjectNote:
    'Это не схема базы этого проекта, поэтому её миграции сюда не пишутся — скачайте их и положите в database/migrations того проекта, для которого они.',
  writeDisabledNote:
    'Запись в проект выключена вне local-окружения. Скачайте миграции и добавьте их в проект сами или включите CHEAP_SQL_SCHEME_SAVE_MIGRATIONS=true.',
  commitWrite: 'Коммит в проект',
  committedTitle: 'Готово',
  committedWritten: 'Сохранено в {path}. Примените их:',
  committedDownloaded: 'Скачано — положите файлы в database/migrations и выполните:',
  dataLossWarning: 'Удалённые таблицы и колонки удалятся вместе с данными.',
  requiredWarning: 'Обязательные (не nullable) и без значения по умолчанию: {columns}. Если в таблицах уже есть строки, миграция упадёт — сделайте их nullable или задайте default.',
  markAdded: 'Новое с последнего коммита',
  markChanged: 'Изменено с последнего коммита',
  pullFromDb: 'Обновить из базы',
  driftTitle: 'База изменилась вне редактора',
  driftDetails: 'Подробнее',
  driftRefresh: 'Обновить',
  driftModalTitle: 'Изменения в базе',
  driftModalHint:
    'Эти изменения сделаны в базе в обход редактора — миграциями или руками. Обновите схему, чтобы холст совпал с базой.',
  pullConfirm: 'Заменить холст текущей структурой базы? Незакоммиченные изменения пропадут.',
  pullFailed: 'Не удалось прочитать базу',
  projectBadge: 'база проекта',

  today: 'сегодня',
  yesterday: 'вчера',
  daysAgo: '{n} {days} назад',
  weekAgo: 'неделю назад',
  weeksAgo: '{n} {weeks} назад',
  monthsAgo: '{n} {months} назад',
}

export const locale: 'en' | 'ru' = config.locale === 'ru' ? 'ru' : 'en'

const messages = locale === 'ru' ? ru : en

export function t(key: MessageKey, vars: Record<string, string | number> = {}): string {
  return messages[key].replace(/\{(\w+)\}/g, (match, name: string) =>
    name in vars ? String(vars[name]) : match,
  )
}

/** Форма слова по числу: en — one/other, ru — one/few/many. */
export function plural(n: number, en: [string, string], ru: [string, string, string]): string {
  if (locale === 'en') return n === 1 ? en[0] : en[1]
  const m10 = Math.abs(n) % 10
  const m100 = Math.abs(n) % 100
  if (m10 === 1 && m100 !== 11) return ru[0]
  if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return ru[1]
  return ru[2]
}

/** «3 tables» / «3 таблицы». */
export function tablesCount(n: number): string {
  const word = plural(n, ['table', 'tables'], ['таблица', 'таблицы', 'таблиц'])
  return `${n.toLocaleString(locale)} ${word}`
}
