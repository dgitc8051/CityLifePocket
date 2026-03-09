const API_BASE = import.meta.env.VITE_API_URL || '/api'

// ---- Unified interface used by all pages ----

export interface ParkingSession {
  id: string
  sessionToken: string | null
  lat: number
  lng: number
  accuracy: number | null
  floor: string
  zone: string
  note: string
  photo: string | null
  isUnderground: boolean
  startedAt: string
  completedAt: string | null
}

export interface CreateSessionInput {
  lat: number
  lng: number
  accuracy: number | null
  floor: string
  zone: string
  note: string
  photo: string | null
  isUnderground: boolean
}

// ---- API response -> ParkingSession ----

function fromApi(data: any): ParkingSession {
  return {
    id: String(data.id),
    sessionToken: data.session_token,
    lat: parseFloat(data.lat),
    lng: parseFloat(data.lng),
    accuracy: data.accuracy ? parseFloat(data.accuracy) : null,
    floor: data.floor || '',
    zone: data.zone || '',
    note: data.custom_note || '',
    photo: null, // photos kept in localStorage only
    isUnderground: Boolean(data.is_underground),
    startedAt: data.started_at,
    completedAt: data.completed_at,
  }
}

// ---- localStorage helpers ----

function getActiveToken(): string | null {
  return localStorage.getItem('activeSessionToken')
}

function setActiveToken(token: string | null) {
  if (token) {
    localStorage.setItem('activeSessionToken', token)
  } else {
    localStorage.removeItem('activeSessionToken')
  }
}

function getCachedSessions(): ParkingSession[] {
  return JSON.parse(localStorage.getItem('parkingSessions') || '[]')
}

function saveCachedSessions(sessions: ParkingSession[]) {
  localStorage.setItem('parkingSessions', JSON.stringify(sessions))
}

// Save photo separately by session id (base64 too large for API)
function savePhoto(sessionId: string, photo: string | null) {
  if (photo) {
    localStorage.setItem(`photo_${sessionId}`, photo)
  }
}

export function getPhoto(sessionId: string): string | null {
  return localStorage.getItem(`photo_${sessionId}`)
}

// ---- Public API ----

export async function createSession(input: CreateSessionInput): Promise<ParkingSession> {
  // Complete any existing active session in cache
  const cached = getCachedSessions()
  const updatedCache: ParkingSession[] = cached.map((s) => ({
    ...s,
    completedAt: s.completedAt || new Date().toISOString(),
  }))

  try {
    const res = await fetch(`${API_BASE}/parking-sessions`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        lat: input.lat,
        lng: input.lng,
        accuracy: input.accuracy,
        floor: input.floor || null,
        zone: input.zone || null,
        custom_note: input.note || null,
        is_underground: input.isUnderground,
      }),
    })
    const json = await res.json()
    if (json.success) {
      const session = fromApi(json.data)
      setActiveToken(json.data.session_token)
      // Store photo locally
      savePhoto(session.id, input.photo)
      session.photo = input.photo
      // Update cache
      updatedCache.unshift(session)
      saveCachedSessions(updatedCache)
      return session
    }
  } catch {
    // API unavailable, use localStorage fallback
  }

  // Fallback: localStorage only
  const session: ParkingSession = {
    id: Date.now().toString(),
    sessionToken: null,
    ...input,
    startedAt: new Date().toISOString(),
    completedAt: null,
  }
  savePhoto(session.id, input.photo)
  updatedCache.unshift(session)
  saveCachedSessions(updatedCache)
  return session
}

export async function getActiveSession(): Promise<ParkingSession | null> {
  const token = getActiveToken()

  if (token) {
    try {
      const res = await fetch(`${API_BASE}/parking-sessions/active?token=${token}`)
      const json = await res.json()
      if (json.success && json.data) {
        const session = fromApi(json.data)
        session.photo = getPhoto(session.id)
        return session
      }
    } catch {
      // Fall through to localStorage
    }
  }

  // Fallback: localStorage
  const sessions = getCachedSessions()
  const active = sessions.find((s) => !s.completedAt) || null
  if (active) {
    active.photo = getPhoto(active.id)
  }
  return active
}

export async function completeSession(session: ParkingSession): Promise<void> {
  if (session.sessionToken) {
    try {
      await fetch(`${API_BASE}/parking-sessions/${session.sessionToken}/complete`, {
        method: 'PATCH',
      })
    } catch {
      // Ignore API error, still update cache
    }
  }

  setActiveToken(null)

  // Update localStorage cache
  const sessions = getCachedSessions()
  const updated = sessions.map((s) =>
    s.id === session.id ? { ...s, completedAt: new Date().toISOString() } : s,
  )
  saveCachedSessions(updated)
}

export async function getSessionHistory(): Promise<ParkingSession[]> {
  // Use localStorage cache as source of truth for history
  // (API returns all sessions without user filtering in MVP)
  const sessions = getCachedSessions()
  return sessions.map((s) => ({
    ...s,
    photo: getPhoto(s.id),
  }))
}
