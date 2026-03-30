import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../services/api'
import './IncidentListPage.css'

interface Incident {
  id: number
  incident_number: string
  title: string
  severity: string
  status: string
  type: string
  category: string
  created_at: string
  current_assignment: { user: { name: string } } | null
}

const STATUS_OPTIONS = ['', 'new', 'triaged', 'assigned', 'in_progress', 'resolved', 'closed']
const SEVERITY_OPTIONS = ['', 'P0', 'P1', 'P2', 'P3']

export function IncidentListPage() {
  const [incidents, setIncidents] = useState<Incident[]>([])
  const [loading, setLoading] = useState(true)
  const [statusFilter, setStatusFilter] = useState('')
  const [severityFilter, setSeverityFilter] = useState('')
  const navigate = useNavigate()

  useEffect(() => {
    const params = new URLSearchParams()
    if (statusFilter) params.set('status', statusFilter)
    if (severityFilter) params.set('severity', severityFilter)
    setLoading(true)
    api.get(`/incidents?${params}`)
      .then((res) => setIncidents(res.data.data))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [statusFilter, severityFilter])

  const statusLabel = (s: string) => {
    const map: Record<string, string> = {
      new: '新建', triaged: '已分級', assigned: '已指派',
      in_progress: '處理中', resolved: '已解決', closed: '已關閉',
    }
    return map[s] || s
  }

  const timeAgo = (dateStr: string) => {
    const diff = Date.now() - new Date(dateStr).getTime()
    const mins = Math.floor(diff / 60000)
    if (mins < 1) return '剛剛'
    if (mins < 60) return `${mins} 分鐘前`
    const hours = Math.floor(mins / 60)
    if (hours < 24) return `${hours} 小時前`
    return `${Math.floor(hours / 24)} 天前`
  }

  return (
    <div className="incident-list-page">
      <div className="page-header">
        <h1 className="page-title">事件管理</h1>
      </div>

      <div className="filters">
        <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
          <option value="">全部狀態</option>
          {STATUS_OPTIONS.filter(Boolean).map((s) => (
            <option key={s} value={s}>{statusLabel(s)}</option>
          ))}
        </select>
        <select value={severityFilter} onChange={(e) => setSeverityFilter(e.target.value)}>
          <option value="">全部嚴重度</option>
          {SEVERITY_OPTIONS.filter(Boolean).map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </select>
      </div>

      {loading ? (
        <div className="page-loading">載入中...</div>
      ) : (
        <div className="incident-list">
          {incidents.map((inc) => (
            <div key={inc.id} className="incident-row" onClick={() => navigate(`/incidents/${inc.id}`)}>
              <span className={`severity severity-${inc.severity.toLowerCase()}`}>{inc.severity}</span>
              <span className="incident-number">{inc.incident_number}</span>
              <span className="incident-title">{inc.title}</span>
              <span className="incident-assignee">
                {inc.current_assignment?.user?.name || '-'}
              </span>
              <span className="incident-status">{statusLabel(inc.status)}</span>
              <span className="incident-time">{timeAgo(inc.created_at)}</span>
            </div>
          ))}
          {incidents.length === 0 && <p className="empty-text">沒有符合條件的事件</p>}
        </div>
      )}
    </div>
  )
}
