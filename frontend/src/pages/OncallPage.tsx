import { useState, useEffect } from 'react'
import api from '../services/api'
import './OncallPage.css'

interface Schedule {
  id: number
  start_at: string
  end_at: string
  team: { id: number; name: string }
  user: { id: number; name: string }
}

export function OncallPage() {
  const [current, setCurrent] = useState<Schedule[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get('/oncall/current')
      .then((res) => setCurrent(res.data.data))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  const formatDate = (d: string) => new Date(d).toLocaleDateString('zh-TW')

  if (loading) return <div className="page-loading">載入中...</div>

  return (
    <div className="oncall-page">
      <h1 className="page-title">值班表</h1>

      <section className="detail-section">
        <h2>目前值班中</h2>
        {current.length === 0 ? (
          <p className="empty-text">目前無人值班</p>
        ) : (
          <div className="oncall-grid">
            {current.map((s) => (
              <div key={s.id} className="oncall-card">
                <div className="oncall-team">{s.team.name}</div>
                <div className="oncall-user">{s.user.name}</div>
                <div className="oncall-dates">
                  {formatDate(s.start_at)} ~ {formatDate(s.end_at)}
                </div>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  )
}
