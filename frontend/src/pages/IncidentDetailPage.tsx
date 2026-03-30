import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import api from '../services/api'
import './IncidentDetailPage.css'

interface AuditLog {
  id: number
  action: string
  actor_type: string
  actor_id: number | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  created_at: string
}

interface Incident {
  id: number
  incident_number: string
  title: string
  description: string
  severity: string
  status: string
  type: string
  category: string
  reporter_name: string
  reporter_contact: string | null
  triage_rule_matched: string | null
  sla_respond_by: string | null
  sla_resolve_by: string | null
  responded_at: string | null
  resolved_at: string | null
  resolution_note: string | null
  resolution_cost: number | null
  escalation_level: number
  created_at: string
  asset: { id: number; name: string; asset_number: string; location: string; model: string } | null
  current_assignment: { user: { name: string }; acked_at: string | null; arrived_at: string | null } | null
  audit_logs: AuditLog[]
}

export function IncidentDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [incident, setIncident] = useState<Incident | null>(null)
  const [loading, setLoading] = useState(true)
  const [actionLoading, setActionLoading] = useState(false)
  const [resolveNote, setResolveNote] = useState('')
  const [showResolveForm, setShowResolveForm] = useState(false)

  const load = () => {
    api.get(`/incidents/${id}`)
      .then((res) => setIncident(res.data.data))
      .catch(() => navigate('/incidents'))
      .finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [id])

  const doAction = async (action: string, body?: object) => {
    setActionLoading(true)
    try {
      await api.post(`/incidents/${id}/${action}`, body || {})
      load()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || '操作失敗'
      alert(msg)
    } finally {
      setActionLoading(false)
    }
  }

  const handleResolve = () => {
    if (!resolveNote.trim()) return
    doAction('resolve', { resolution_note: resolveNote })
    setShowResolveForm(false)
  }

  const statusLabel = (s: string) => {
    const map: Record<string, string> = {
      new: '新建', triaged: '已分級', assigned: '已指派',
      in_progress: '處理中', resolved: '已解決', closed: '已關閉',
    }
    return map[s] || s
  }

  const formatTime = (dateStr: string) => {
    return new Date(dateStr).toLocaleString('zh-TW')
  }

  const slaRemaining = (deadline: string | null) => {
    if (!deadline) return null
    const diff = new Date(deadline).getTime() - Date.now()
    if (diff <= 0) return '已超時'
    const mins = Math.floor(diff / 60000)
    if (mins < 60) return `${mins} 分鐘`
    const hours = Math.floor(mins / 60)
    if (hours < 24) return `${hours} 小時 ${mins % 60} 分鐘`
    return `${Math.floor(hours / 24)} 天`
  }

  const actionLabel = (action: string) => {
    const map: Record<string, string> = {
      created: '建立事件',
      auto_triaged: '自動分級',
      auto_assigned: '自動指派',
      acknowledged: '已接手',
      arrived: '已到場',
      resolved: '已解決',
      escalated: '已升級',
      updated: '已更新',
    }
    return map[action] || action
  }

  if (loading || !incident) return <div className="page-loading">載入中...</div>

  const canAck = incident.status === 'assigned' && !incident.current_assignment?.acked_at
  const canArrive = incident.current_assignment?.acked_at && !incident.current_assignment?.arrived_at && incident.type === 'equipment'
  const canResolve = ['assigned', 'in_progress'].includes(incident.status)
  const canEscalate = !['resolved', 'closed'].includes(incident.status)

  return (
    <div className="incident-detail">
      <button className="btn-back" onClick={() => navigate('/incidents')}>← 返回列表</button>

      <div className="detail-header">
        <span className={`severity severity-${incident.severity.toLowerCase()}`}>{incident.severity}</span>
        <span className="detail-number">{incident.incident_number}</span>
        <span className={`status-badge status-${incident.status}`}>{statusLabel(incident.status)}</span>
      </div>

      <h1 className="detail-title">{incident.title}</h1>

      <div className="detail-grid">
        <div className="detail-main">
          {/* Info */}
          <section className="detail-section">
            <h2>事件資訊</h2>
            <div className="info-grid">
              <div className="info-item"><span className="info-label">類型</span><span>{incident.type === 'equipment' ? '設備維修' : '系統異常'}</span></div>
              <div className="info-item"><span className="info-label">分類</span><span>{incident.category}</span></div>
              <div className="info-item"><span className="info-label">回報者</span><span>{incident.reporter_name}</span></div>
              <div className="info-item"><span className="info-label">指派</span><span>{incident.current_assignment?.user?.name || '未指派'}</span></div>
              <div className="info-item"><span className="info-label">分級規則</span><span>{incident.triage_rule_matched || '-'}</span></div>
              <div className="info-item"><span className="info-label">建立時間</span><span>{formatTime(incident.created_at)}</span></div>
            </div>
          </section>

          {/* Asset */}
          {incident.asset && (
            <section className="detail-section">
              <h2>關聯設備</h2>
              <div className="info-grid">
                <div className="info-item"><span className="info-label">設備</span><span>{incident.asset.name} #{incident.asset.asset_number}</span></div>
                <div className="info-item"><span className="info-label">位置</span><span>{incident.asset.location}</span></div>
                <div className="info-item"><span className="info-label">型號</span><span>{incident.asset.model}</span></div>
              </div>
            </section>
          )}

          {/* Description */}
          <section className="detail-section">
            <h2>問題描述</h2>
            <p className="detail-description">{incident.description}</p>
          </section>

          {/* SLA */}
          <section className="detail-section">
            <h2>SLA</h2>
            <div className="info-grid">
              <div className="info-item">
                <span className="info-label">回應期限</span>
                <span className={incident.responded_at ? '' : (slaRemaining(incident.sla_respond_by) === '已超時' ? 'sla-breach' : '')}>
                  {incident.responded_at ? `已回應 (${formatTime(incident.responded_at)})` : slaRemaining(incident.sla_respond_by)}
                </span>
              </div>
              <div className="info-item">
                <span className="info-label">解決期限</span>
                <span className={incident.resolved_at ? '' : (slaRemaining(incident.sla_resolve_by) === '已超時' ? 'sla-breach' : '')}>
                  {incident.resolved_at ? `已解決 (${formatTime(incident.resolved_at)})` : slaRemaining(incident.sla_resolve_by)}
                </span>
              </div>
            </div>
          </section>

          {/* Actions */}
          {(canAck || canArrive || canResolve || canEscalate) && (
            <section className="detail-section">
              <h2>操作</h2>
              <div className="action-buttons">
                {canAck && <button className="action-btn ack" onClick={() => doAction('acknowledge')} disabled={actionLoading}>確認接手</button>}
                {canArrive && <button className="action-btn arrive" onClick={() => doAction('arrive')} disabled={actionLoading}>確認到場</button>}
                {canResolve && !showResolveForm && <button className="action-btn resolve" onClick={() => setShowResolveForm(true)}>標記解決</button>}
                {canEscalate && <button className="action-btn escalate" onClick={() => doAction('escalate')} disabled={actionLoading}>升級處理</button>}
              </div>
              {showResolveForm && (
                <div className="resolve-form">
                  <textarea
                    placeholder="請描述解決方式..."
                    value={resolveNote}
                    onChange={(e) => setResolveNote(e.target.value)}
                    rows={3}
                  />
                  <div className="resolve-actions">
                    <button className="action-btn resolve" onClick={handleResolve} disabled={actionLoading || !resolveNote.trim()}>確認解決</button>
                    <button className="action-btn cancel" onClick={() => setShowResolveForm(false)}>取消</button>
                  </div>
                </div>
              )}
            </section>
          )}

          {/* Resolution */}
          {incident.resolution_note && (
            <section className="detail-section">
              <h2>解決紀錄</h2>
              <p className="detail-description">{incident.resolution_note}</p>
              {incident.resolution_cost != null && (
                <p className="resolution-cost">費用: NT${Number(incident.resolution_cost).toLocaleString()}</p>
              )}
            </section>
          )}
        </div>

        {/* Timeline */}
        <div className="detail-sidebar">
          <section className="detail-section">
            <h2>時間軸</h2>
            <div className="timeline">
              {incident.audit_logs.map((log) => (
                <div key={log.id} className="timeline-item">
                  <div className="timeline-time">{formatTime(log.created_at)}</div>
                  <div className="timeline-action">
                    <span className="timeline-actor">[{log.actor_type}]</span>
                    {' '}{actionLabel(log.action)}
                    {log.after && Object.keys(log.after).length > 0 && (
                      <div className="timeline-detail">
                        {Object.entries(log.after).map(([k, v]) => (
                          <span key={k}>{k}: {String(v)}</span>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </section>
        </div>
      </div>
    </div>
  )
}
