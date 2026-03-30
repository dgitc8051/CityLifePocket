import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import api from '../services/api'
import './DashboardPage.css'

interface Incident {
  id: number
  incident_number: string
  title: string
  severity: string
  status: string
  type: string
  created_at: string
  sla_respond_by: string | null
  responded_at: string | null
  current_assignment: { user: { name: string } } | null
}

export function DashboardPage() {
  const [incidents, setIncidents] = useState<Incident[]>([])
  const [loading, setLoading] = useState(true)
  const navigate = useNavigate()

  useEffect(() => {
    api.get('/incidents?per_page=50')
      .then((res) => setIncidents(res.data.data))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  const pending = incidents.filter((i) => ['new', 'triaged', 'assigned'].includes(i.status))
  const today = incidents.filter((i) => {
    const d = new Date(i.created_at)
    const now = new Date()
    return d.toDateString() === now.toDateString()
  })
  const resolved = incidents.filter((i) => i.status === 'resolved' || i.status === 'closed')
  const slaRate = incidents.length > 0
    ? Math.round((resolved.length / incidents.length) * 100)
    : 0

  const urgent = incidents
    .filter((i) => ['new', 'triaged', 'assigned'].includes(i.status) && (i.severity === 'P0' || i.severity === 'P1'))
    .slice(0, 5)

  const recent = incidents.slice(0, 8)

  const severityClass = (s: string) => `severity severity-${s.toLowerCase()}`
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

  if (loading) return <div className="page-loading">載入中...</div>

  return (
    <div className="dashboard">
      <h1 className="page-title">Dashboard</h1>

      <div className="stat-cards">
        <div className="stat-card">
          <div className="stat-value">{pending.length}</div>
          <div className="stat-label">待處理</div>
        </div>
        <div className="stat-card">
          <div className="stat-value">{today.length}</div>
          <div className="stat-label">今日新增</div>
        </div>
        <div className="stat-card">
          <div className="stat-value">{slaRate}%</div>
          <div className="stat-label">解決率</div>
        </div>
        <div className="stat-card">
          <div className="stat-value">{incidents.length}</div>
          <div className="stat-label">總事件數</div>
        </div>
      </div>

      {urgent.length > 0 && (
        <section className="dashboard-section">
          <h2 className="section-title">需要立即關注</h2>
          <div className="incident-list">
            {urgent.map((inc) => (
              <div key={inc.id} className="incident-row urgent" onClick={() => navigate(`/incidents/${inc.id}`)}>
                <span className={severityClass(inc.severity)}>{inc.severity}</span>
                <span className="incident-number">{inc.incident_number}</span>
                <span className="incident-title">{inc.title}</span>
                <span className="incident-time">{timeAgo(inc.created_at)}</span>
              </div>
            ))}
          </div>
        </section>
      )}

      <section className="dashboard-section">
        <h2 className="section-title">最近事件</h2>
        <div className="incident-list">
          {recent.map((inc) => (
            <div key={inc.id} className="incident-row" onClick={() => navigate(`/incidents/${inc.id}`)}>
              <span className={severityClass(inc.severity)}>{inc.severity}</span>
              <span className="incident-number">{inc.incident_number}</span>
              <span className="incident-title">{inc.title}</span>
              <span className="incident-status">{statusLabel(inc.status)}</span>
              <span className="incident-time">{timeAgo(inc.created_at)}</span>
            </div>
          ))}
          {recent.length === 0 && <p className="empty-text">目前沒有事件</p>}
        </div>
      </section>
    </div>
  )
}
