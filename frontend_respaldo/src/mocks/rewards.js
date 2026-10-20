// ============================================================
// MOCKS — Campus Digital / Equipo 7 Recompensas
// ============================================================

// ── Usuarios mock ──
export const mockUsers = {
  student: {
    role: 'student',
    name: 'María López',
    initials: 'ML',
    id: 'A2024-0831',
    roleLabel: null,
    balance: 3420,
  },
  professor: {
    role: 'professor',
    name: 'Dr. Carlos Ramírez',
    initials: 'DR',
    id: 'P-10234',
    roleLabel: 'Profesor',
    balance: 2180,
  },
  admin: {
    role: 'admin',
    name: 'Andrés Ramírez',
    initials: 'AR',
    id: 'A001-ADMIN',
    roleLabel: 'Administrador',
    balance: null,
  },
}

// ── Dashboard alumno ──
export const studentDashboardMock = {
  balance: 3420,
  activeRedemptions: 2,
  availableCampaigns: 5,
  featuredCampaign: {
    id: 'c1',
    title: 'Semana Doble Puntos',
    description: 'Realiza actividades participantes y gana el doble de puntos.',
    period: 'Del 15 al 22 de septiembre',
    multiplier: '2x',
  },
  featuredRewards: [
    { id: 'r1', name: 'Combo cafetería', points: 450, image: '☕', category: 'Cafetería' },
    { id: 'r2', name: 'Hoodie Campus', points: 2000, image: '🧥', category: 'Ropa' },
    { id: 'r3', name: 'Audífonos inalámbricos', points: 1200, image: '🎧', category: 'Electrónica' },
  ],
  recentActivity: [
    { date: '12 sep 2026', concept: 'Participación en evento', points: 200, status: 'Completado', type: 'earn' },
    { date: '10 sep 2026', concept: 'Canje cafetería', points: -450, status: 'Entregado', type: 'redeem' },
    { date: '08 sep 2026', concept: 'Encuesta universitaria', points: 100, status: 'Completado', type: 'earn' },
  ],
}

// ── Dashboard profesor ──
export const professorDashboardMock = {
  balance: 2180,
  pendingValidations: 6,
  activeCampaigns: 3,
  featuredCampaign: {
    title: 'Incentivo por participación académica',
    description: 'Reconoce el esfuerzo e impulsa grandes logros.',
    multiplier: '2x',
    period: 'Del 8 al 30 de septiembre',
  },
  pendingRedemptions: [
    { id: 'rd1', student: 'Laura Martínez', initials: 'LM', reward: 'Café Americano', code: 'OC-20260912-001', date: '12/09/2026', status: 'Pendiente' },
    { id: 'rd2', student: 'Juan Pérez', initials: 'JP', reward: 'Libreta Campus', code: 'OC-20260911-018', date: '11/09/2026', status: 'Pendiente' },
    { id: 'rd3', student: 'Ana García', initials: 'AG', reward: 'Termo Campus', code: 'OC-20260910-013', date: '10/09/2026', status: 'Pendiente' },
    { id: 'rd4', student: 'Carlos Rodríguez', initials: 'CR', reward: 'Hoodie Campus', code: 'OC-20260910-011', date: '10/09/2026', status: 'Pendiente' },
  ],
  groupParticipation: [
    { group: '1°A', pct: 72 },
    { group: '1°B', pct: 85 },
    { group: '3°A', pct: 90 },
    { group: '5°A', pct: 68 },
    { group: '5°B', pct: 92 },
  ],
}

// ── Dashboard administrador ──
export const adminDashboardMock = {
  period: 'Septiembre 2026',
  pointsIssuedMonth: 1245320,
  completedRedemptions: 3482,
  fraudAlerts: 12,
  adheredBusinesses: 86,
  governance: {
    emissionLimits: 2,
    expiringPoints: 348,
    detectedFrauds: 12,
    pendingCampaigns: 3,
  },
  chartData: {
    labels: ['Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep'],
    issued: [95000, 120000, 110000, 180000, 200000, 195000],
    redeemed: [40000, 55000, 48000, 90000, 130000, 140000],
  },
  auditLogs: [
    { id: 'a1', user: 'Laura Méndez', initials: 'LM', action: 'Creación de campaña', entity: 'Campaña - Bienestar U', date: '12/09/2026 22:14' },
    { id: 'a2', user: 'José Ramírez', initials: 'JR', action: 'Ajuste de regla', entity: 'Regla - Puntos por compra', date: '12/09/2026 19:03' },
    { id: 'a3', user: 'Sofía Campos', initials: 'SC', action: 'Publicación de recompensa', entity: 'Recompensa - Termo Campus', date: '12/09/2026 16:27' },
    { id: 'a4', user: 'Andrés Ramírez', initials: 'AR', action: 'Aprobación de negocio', entity: 'Negocio - Café Campus', date: '12/09/2026 12:11' },
  ],
}

// ── Recompensas ──
export const rewardsMock = [
  { id: 'r1', name: 'Combo cafetería', business: 'Café Campus', points: 450, stock: 30, image: '☕', validUntil: '31/10/2026', status: 'Activo' },
  { id: 'r2', name: 'Hoodie Campus', business: 'Tienda Campus', points: 2000, stock: 12, image: '🧥', validUntil: '15/12/2026', status: 'Activo' },
  { id: 'r3', name: 'Audífonos inalámbricos', business: 'Tienda Campus', points: 1200, stock: 8, image: '🎧', validUntil: '30/11/2026', status: 'Activo' },
  { id: 'r4', name: 'Termo Campus', business: 'Tienda Campus', points: 800, stock: 20, image: '🥤', validUntil: '31/12/2026', status: 'Activo' },
  { id: 'r5', name: 'Libreta Campus', business: 'Librería Campus', points: 300, stock: 50, image: '📓', validUntil: '30/11/2026', status: 'Activo' },
  { id: 'r6', name: 'Café Americano', business: 'Café Campus', points: 150, stock: 100, image: '☕', validUntil: '31/10/2026', status: 'Activo' },
  { id: 'r7', name: 'Mochila Campus', business: 'Tienda Campus', points: 3500, stock: 5, image: '🎒', validUntil: '15/01/2027', status: 'Inactivo' },
]

// ── Campañas ──
export const campaignsMock = [
  { id: 'c1', title: 'Semana Doble Puntos', description: 'Gana el doble de puntos en todas tus actividades.', multiplier: '2x', period: 'Del 15 al 22 de septiembre', status: 'Activa', scope: 'Global' },
  { id: 'c2', title: 'Incentivo Académico Docente', description: 'Recompensas para alumnos destacados por su profesor.', multiplier: '1.5x', period: 'Del 8 al 30 de septiembre', status: 'Activa', scope: 'Académico' },
  { id: 'c3', title: 'Feria del Conocimiento', description: '100 pts extra por asistir a conferencias universitarias.', multiplier: '+100 pts', period: 'Del 20 al 25 de septiembre', status: 'Activa', scope: 'Evento' },
  { id: 'c4', title: 'Campaña de Encuestas', description: 'Gana puntos respondiendo encuestas de satisfacción.', multiplier: '+50 pts', period: 'Permanente', status: 'Activa', scope: 'Global' },
  { id: 'c5', title: 'Mes de la Comunidad', description: 'Puntos extra por participación en actividades comunitarias.', multiplier: '1.2x', period: 'Todo octubre 2026', status: 'Próxima', scope: 'Global' },
]

// ── Canjes ──
export const redemptionsMock = [
  { id: 'rd1', student: 'María López', initials: 'ML', reward: 'Café Americano', code: 'OC-20260912-001', points: 150, date: '12/09/2026', status: 'Entregado' },
  { id: 'rd2', student: 'María López', initials: 'ML', reward: 'Audífonos inalámbricos', code: 'OC-20260902-044', points: 1200, date: '02/09/2026', status: 'Reservado' },
  { id: 'rd3', student: 'Juan Pérez', initials: 'JP', reward: 'Libreta Campus', code: 'OC-20260911-018', points: 300, date: '11/09/2026', status: 'Pendiente' },
  { id: 'rd4', student: 'Ana García', initials: 'AG', reward: 'Termo Campus', code: 'OC-20260910-013', points: 800, date: '10/09/2026', status: 'Validado' },
  { id: 'rd5', student: 'Carlos Rodríguez', initials: 'CR', reward: 'Hoodie Campus', code: 'OC-20260910-011', points: 2000, date: '10/09/2026', status: 'Pendiente' },
  { id: 'rd6', student: 'Laura Martínez', initials: 'LM', reward: 'Café Americano', code: 'OC-20260908-003', points: 150, date: '08/09/2026', status: 'Cancelado' },
]

// ── Actividad del alumno ──
export const activityMock = [
  { date: '12 sep 2026', concept: 'Participación en evento', points: 200, status: 'Completado', type: 'earn', source: 'Eventos' },
  { date: '10 sep 2026', concept: 'Canje cafetería', points: -450, status: 'Entregado', type: 'redeem', source: 'Cafetería' },
  { date: '08 sep 2026', concept: 'Encuesta universitaria', points: 100, status: 'Completado', type: 'earn', source: 'Encuestas' },
  { date: '05 sep 2026', concept: 'Compra en tienda', points: 80, status: 'Completado', type: 'earn', source: 'Tienda' },
  { date: '02 sep 2026', concept: 'Asistencia a conferencia', points: 120, status: 'Completado', type: 'earn', source: 'Eventos' },
  { date: '28 ago 2026', concept: 'Canje audífonos (reservado)', points: -1200, status: 'Reservado', type: 'redeem', source: 'Tienda' },
]

// ── Negocios adheridos ──
export const businessesMock = [
  { id: 'b1', name: 'Café Campus', responsible: 'Ana Torres', joinDate: '01/09/2026', status: 'Activo', participation: 'Alta' },
  { id: 'b2', name: 'Tienda Campus', responsible: 'Marco Ríos', joinDate: '15/08/2026', status: 'Activo', participation: 'Alta' },
  { id: 'b3', name: 'Librería Campus', responsible: 'Elena Mora', joinDate: '20/08/2026', status: 'Activo', participation: 'Media' },
  { id: 'b4', name: 'Fotocopiadora Central', responsible: 'Luis Soto', joinDate: '05/09/2026', status: 'Activo', participation: 'Baja' },
  { id: 'b5', name: 'Papelería Norte', responsible: 'Carmen Gil', joinDate: '10/09/2026', status: 'Pendiente', participation: '—' },
  { id: 'b6', name: 'Restaurante Universitario', responsible: 'Roberto Paz', joinDate: '01/07/2026', status: 'Suspendido', participation: 'Media' },
]

// ── Reglas de acumulación ──
export const earningRulesMock = [
  { id: 'er1', name: '1 punto por cada $2', description: 'Compras en negocios adheridos', value: '$2 = 1 pt', scope: 'Global', maxPerOp: 500, status: 'Activa' },
  { id: 'er2', name: 'Puntos por evento', description: 'Asistencia a eventos universitarios', value: '100 pts/evento', scope: 'Eventos', maxPerOp: 300, status: 'Activa' },
  { id: 'er3', name: 'Puntos por campaña doble', description: 'Multiplicador de campaña activa', value: '2x', scope: 'Global', maxPerOp: null, status: 'Activa' },
  { id: 'er4', name: 'Puntos por encuesta', description: 'Responder encuestas de satisfacción', value: '50 pts/encuesta', scope: 'Encuestas', maxPerOp: 150, status: 'Activa' },
  { id: 'er5', name: 'Puntos académicos', description: 'Reconocimiento docente', value: '200 pts', scope: 'Académico', maxPerOp: 200, status: 'Inactiva' },
]

// ── Auditoría ──
export const auditMock = [
  { id: 'a1', user: 'Laura Méndez', initials: 'LM', action: 'Creación de campaña', entity: 'Campaña - Bienestar U', date: '12/09/2026 22:14', ip: '192.168.1.45', correlation: 'COR-0912-001' },
  { id: 'a2', user: 'José Ramírez', initials: 'JR', action: 'Ajuste de regla', entity: 'Regla - Puntos por compra', date: '12/09/2026 19:03', ip: '192.168.1.12', correlation: 'COR-0912-002' },
  { id: 'a3', user: 'Sofía Campos', initials: 'SC', action: 'Publicación de recompensa', entity: 'Recompensa - Termo Campus', date: '12/09/2026 16:27', ip: '192.168.1.88', correlation: 'COR-0912-003' },
  { id: 'a4', user: 'Andrés Ramírez', initials: 'AR', action: 'Aprobación de negocio', entity: 'Negocio - Café Campus', date: '12/09/2026 12:11', ip: '10.0.0.1', correlation: 'COR-0912-004' },
  { id: 'a5', user: 'María López', initials: 'ML', action: 'Canje de recompensa', entity: 'Recompensa - Café Americano', date: '12/09/2026 09:55', ip: '192.168.2.30', correlation: 'COR-0912-005' },
]

// ── Configuraciones sensibles ──
export const settingsMock = [
  { id: 's1', config: 'Tasa base de acumulación', value: '$2 = 1 punto', version: 'v3.2', lastModified: '10/09/2026', status: 'Activo' },
  { id: 's2', config: 'Tope diario por estudiante', value: '500 puntos', version: 'v2.1', lastModified: '01/09/2026', status: 'Activo' },
  { id: 's3', config: 'Expiración de puntos', value: '12 meses sin actividad', version: 'v1.5', lastModified: '15/08/2026', status: 'Activo' },
  { id: 's4', config: 'Umbral antifraude', value: '3 transacciones/hora', version: 'v2.0', lastModified: '05/09/2026', status: 'Activo' },
  { id: 's5', config: 'Puntos mínimos para canje', value: '100 puntos', version: 'v1.0', lastModified: '01/08/2026', status: 'Activo' },
  { id: 's6', config: 'Multipicador máximo campaña', value: '3x', version: 'v1.1', lastModified: '20/08/2026', status: 'Inactivo' },
]

// ── Ledger global ──
export const ledgerMock = [
  { id: 'l1', student: 'María López', sid: 'A2024-0831', movement: 'earn', points: 200, reference: 'EVT-20260912', date: '12/09/2026', status: 'Completado' },
  { id: 'l2', student: 'María López', sid: 'A2024-0831', movement: 'redeem', points: -450, reference: 'OC-20260910-001', date: '10/09/2026', status: 'Entregado' },
  { id: 'l3', student: 'Juan Pérez', sid: 'A2024-0722', movement: 'earn', points: 100, reference: 'ENC-20260908', date: '08/09/2026', status: 'Completado' },
  { id: 'l4', student: 'Ana García', sid: 'A2024-0901', movement: 'earn', points: 500, reference: 'EVT-20260905', date: '05/09/2026', status: 'Completado' },
  { id: 'l5', student: 'Carlos Rodríguez', sid: 'A2024-0655', movement: 'reverse', points: -200, reference: 'REV-20260903', date: '03/09/2026', status: 'Revertido' },
]

// ── Integración / Observabilidad ──
export const integrationMock = [
  { id: 'i1', name: 'API de Recompensas', type: 'API', endpoint: '/api/v1/points/*', status: 'OK', lastCheck: '02/10/2026 13:00', uptime: '99.9%' },
  { id: 'i2', name: 'Webhook Eventos', type: 'Webhook', endpoint: '/webhooks/events', status: 'OK', lastCheck: '02/10/2026 12:55', uptime: '99.7%' },
  { id: 'i3', name: 'Job Ledger Sync', type: 'Job', endpoint: 'queue:ledger-sync', status: 'Advertencia', lastCheck: '02/10/2026 12:50', uptime: '97.2%' },
  { id: 'i4', name: 'Cola de Eventos', type: 'Queue', endpoint: 'redis:events', status: 'OK', lastCheck: '02/10/2026 12:45', uptime: '100%' },
  { id: 'i5', name: 'Servicio Antifraude', type: 'Service', endpoint: 'fraud-detector', status: 'Error', lastCheck: '02/10/2026 11:30', uptime: '88.1%' },
  { id: 'i6', name: 'Reintentos Webhooks', type: 'Retry', endpoint: 'queue:webhook-retry', status: 'OK', lastCheck: '02/10/2026 13:00', uptime: '99.5%' },
]
