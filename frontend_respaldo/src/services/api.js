/**
 * API Service — Campus Digital (Equipo 7: Programa de Recompensas y Puntos)
 * Comunica el frontend Vue 3 con el backend Laravel / MongoDB
 * Base URL: /api/v1 (proxy inverso en Vite hacia backend)
 */

const BASE_URL = '/api/v1'

export function getStoredToken() {
  return localStorage.getItem('campus_token') || ''
}

export function setStoredToken(token) {
  if (token) {
    localStorage.setItem('campus_token', token)
  } else {
    localStorage.removeItem('campus_token')
  }
}

export function getStoredUser() {
  try {
    const raw = localStorage.getItem('campus_user')
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export function setStoredUser(user) {
  if (user) {
    localStorage.setItem('campus_user', JSON.stringify(user))
  } else {
    localStorage.removeItem('campus_user')
  }
}

async function request(method, endpoint, data = null, customHeaders = {}) {
  const token = getStoredToken()
  const headers = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    ...customHeaders,
  }

  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  const config = {
    method,
    headers,
  }

  if (data !== null && ['POST', 'PUT', 'PATCH'].includes(method.toUpperCase())) {
    config.body = JSON.stringify(data)
  }

  const response = await fetch(`${BASE_URL}${endpoint}`, config)

  if (!response.ok) {
    const errorBody = await response.json().catch(() => ({ error: response.statusText }))
    const message = errorBody.message || errorBody.error || `HTTP ${response.status}: ${response.statusText}`
    const error = new Error(message)
    error.status = response.status
    error.data = errorBody
    throw error
  }

  return response.json()
}

export const api = {
  // ── 1. Autenticación y Perfil ──────────────────────────────
  auth: {
    login: (credentials) => request('POST', '/auth/login', credentials),
    logout: () => request('POST', '/auth/logout'),
    me: () => request('GET', '/auth/me'),
    switchDemoUser: (role) => request('POST', '/auth/switch-demo-user', { role }),
  },

  // ── 2. Dashboard ───────────────────────────────────────────
  dashboard: {
    getStats: () => request('GET', '/dashboard/stats'),
  },

  // ── 3. Puntos y Ledger (Motor Contable) ────────────────────
  points: {
    getMyBalance: () => request('GET', '/points/my-balance'),
    getMyHistory: (page = 1) => request('GET', `/points/my-history?page=${page}`),
    getBalance: (studentId) => request('GET', `/points/balance/${studentId}`),
    getHistory: (studentId, page = 1) => request('GET', `/points/history/${studentId}?page=${page}`),
    getLedger: (params = {}) => {
      const qs = new URLSearchParams(params).toString()
      return request('GET', `/points/ledger${qs ? `?${qs}` : ''}`)
    },
    earn: (payload) => request('POST', '/points/earn', payload),
    redeem: (payload) => request('POST', '/points/redeem', payload),
    reverse: (payload) => request('POST', '/points/reverse', payload),
    reconcile: (studentId) => request('POST', `/points/reconcile/${studentId}`),
  },

  // ── 4. Recompensas (Catálogo) ──────────────────────────────
  rewards: {
    list: (params = {}) => {
      const qs = new URLSearchParams(params).toString()
      return request('GET', `/rewards${qs ? `?${qs}` : ''}`)
    },
    get: (id) => request('GET', `/rewards/${id}`),
    create: (payload) => request('POST', '/rewards', payload),
    update: (id, payload) => request('PUT', `/rewards/${id}`, payload),
    delete: (id) => request('DELETE', `/rewards/${id}`),
  },

  // ── 5. Canjes (Ciclo de Vida) ──────────────────────────────
  redemptions: {
    list: (params = {}) => {
      const qs = new URLSearchParams(params).toString()
      return request('GET', `/redemptions${qs ? `?${qs}` : ''}`)
    },
    get: (id) => request('GET', `/redemptions/${id}`),
    create: (payload) => request('POST', '/redemptions', payload),
    validateCode: (code) => request('POST', '/redemptions/validate-code', { code }),
    deliver: (id, payload = {}) => request('POST', `/redemptions/${id}/deliver`, payload),
    cancel: (id, reason = '') => request('POST', `/redemptions/${id}/cancel`, { reason }),
    getEvents: (id) => request('GET', `/redemptions/${id}/events`),
  },

  // ── 6. Negocios Participantes ──────────────────────────────
  businesses: {
    list: (params = {}) => {
      const qs = new URLSearchParams(params).toString()
      return request('GET', `/businesses${qs ? `?${qs}` : ''}`)
    },
    get: (id) => request('GET', `/businesses/${id}`),
    create: (payload) => request('POST', '/businesses', payload),
    update: (id, payload) => request('PUT', `/businesses/${id}`, payload),
    toggleStatus: (id) => request('POST', `/businesses/${id}/toggle-status`),
  },

  // ── 7. Reglas de Acumulación ───────────────────────────────
  rules: {
    list: (params = {}) => {
      const qs = new URLSearchParams(params).toString()
      return request('GET', `/rules${qs ? `?${qs}` : ''}`)
    },
    get: (id) => request('GET', `/rules/${id}`),
    create: (payload) => request('POST', '/rules', payload),
    update: (id, payload) => request('PUT', `/rules/${id}`, payload),
    toggleStatus: (id) => request('POST', `/rules/${id}/toggle-status`),
  },

  // ── 8. Campañas Promocionales y Académicas ─────────────────
  campaigns: {
    list: (params = {}) => {
      const qs = new URLSearchParams(params).toString()
      return request('GET', `/campaigns${qs ? `?${qs}` : ''}`)
    },
    get: (id) => request('GET', `/campaigns/${id}`),
    create: (payload) => request('POST', '/campaigns', payload),
    propose: (payload) => request('POST', '/campaigns/propose', payload),
    update: (id, payload) => request('PUT', `/campaigns/${id}`, payload),
    toggleStatus: (id) => request('POST', `/campaigns/${id}/toggle-status`),
  },

  // ── 9. Gobierno de Puntos, Políticas y Fraude ──────────────
  governance: {
    getPolicies: () => request('GET', '/governance/policies'),
    createPolicy: (payload) => request('POST', '/governance/policies', payload),
    updatePolicy: (id, payload) => request('PUT', `/governance/policies/${id}`, payload),
    manualAdjustment: (payload) => request('POST', '/governance/adjustments', payload),
    getFraudFlags: (params = {}) => {
      const qs = new URLSearchParams(params).toString()
      return request('GET', `/governance/fraud-flags${qs ? `?${qs}` : ''}`)
    },
    reviewFraudFlag: (id, payload) => request('POST', `/governance/fraud-flags/${id}/review`, payload),
  },

  // ── 10. Auditoría y Trazabilidad ───────────────────────────
  audit: {
    getLogs: (params = {}) => {
      const qs = new URLSearchParams(params).toString()
      return request('GET', `/audit/logs${qs ? `?${qs}` : ''}`)
    },
    getLog: (id) => request('GET', `/audit/logs/${id}`),
    getCorrelation: (id) => request('GET', `/audit/correlations/${id}`),
  },

  // ── 11. Analítica ──────────────────────────────────────────
  analytics: {
    getOverview: () => request('GET', '/analytics/overview'),
    getProfessorAnalytics: () => request('GET', '/analytics/professor'),
  },

  // ── 12. Integraciones y Observabilidad ─────────────────────
  integrations: {
    getLogs: (params = {}) => {
      const qs = new URLSearchParams(params).toString()
      return request('GET', `/integrations/logs${qs ? `?${qs}` : ''}`)
    },
    getWebhooks: (params = {}) => {
      const qs = new URLSearchParams(params).toString()
      return request('GET', `/integrations/webhooks${qs ? `?${qs}` : ''}`)
    },
    retryWebhook: (id) => request('POST', `/integrations/webhooks/${id}/retry`),
    ingestEvent: (payload) => request('POST', '/integrations/ingest-event', payload),
  },

  // ── 13. Configuraciones y Versiones ────────────────────────
  settings: {
    list: () => request('GET', '/settings'),
    update: (key, payload) => request('PUT', `/settings/${key}`, payload),
    getVersions: () => request('GET', '/settings/versions'),
    createVersion: (payload) => request('POST', '/settings/versions', payload),
    transitionVersion: (id, status) => request('POST', `/settings/versions/${id}/transition`, { status }),
  },
}

// Compatibilidad hacia atrás con métodos planos previos
api.getDashboardStats = api.dashboard.getStats
api.getBalance        = api.points.getBalance
api.getHistory        = api.points.getHistory
api.earnPoints        = api.points.earn
api.redeemPoints      = api.points.redeem
api.reversePoints     = api.points.reverse
api.reconcileAccount  = api.points.reconcile
api.getRewards        = api.rewards.list
api.getReward         = api.rewards.get
api.createReward      = api.rewards.create
api.updateReward      = api.rewards.update
api.deleteReward      = api.rewards.delete
api.getRedemptions    = api.redemptions.list
api.getRedemption     = api.redemptions.get
api.getAuditLogs      = api.audit.getLogs
