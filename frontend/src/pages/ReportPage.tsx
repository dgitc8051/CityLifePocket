import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import api from '../services/api'
import './ReportPage.css'

interface AssetInfo {
  id: number
  asset_number: string
  name: string
  type: string
  category: string
  location: string | null
  model: string | null
  team: string
  last_maintained_at: string | null
}

export function ReportPage() {
  const { qrCode } = useParams<{ qrCode: string }>()
  const [asset, setAsset] = useState<AssetInfo | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [reporterName, setReporterName] = useState('')
  const [reporterContact, setReporterContact] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [submitted, setSubmitted] = useState<string | null>(null)

  useEffect(() => {
    if (!qrCode) return
    api.get(`/assets/${qrCode}/report`)
      .then((res) => setAsset(res.data.data))
      .catch(() => setNotFound(true))
  }, [qrCode])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!asset) return
    setSubmitting(true)
    try {
      const res = await api.post('/incidents', {
        title,
        description,
        category: asset.category,
        type: asset.type,
        asset_id: asset.id,
        reporter_name: reporterName,
        reporter_contact: reporterContact || undefined,
      })
      setSubmitted(res.data.data.incident_number)
    } catch (err) {
      alert('送出失敗，請稍後再試')
    } finally {
      setSubmitting(false)
    }
  }

  if (notFound) {
    return (
      <div className="report-page">
        <div className="report-card">
          <h1>找不到此設備</h1>
          <p>QR Code 無效或設備已停用</p>
        </div>
      </div>
    )
  }

  if (submitted) {
    return (
      <div className="report-page">
        <div className="report-card">
          <div className="report-success">
            <h1>報修成功</h1>
            <p className="report-number">{submitted}</p>
            <p>我們已收到您的報修，將盡快派人處理。</p>
          </div>
        </div>
      </div>
    )
  }

  if (!asset) {
    return (
      <div className="report-page">
        <div className="report-card">載入中...</div>
      </div>
    )
  }

  return (
    <div className="report-page">
      <div className="report-card">
        <h1 className="report-title">設備報修</h1>

        <div className="asset-info">
          <div className="asset-info-row">
            <span className="asset-label">設備</span>
            <span>{asset.name} #{asset.asset_number}</span>
          </div>
          {asset.model && (
            <div className="asset-info-row">
              <span className="asset-label">型號</span>
              <span>{asset.model}</span>
            </div>
          )}
          {asset.location && (
            <div className="asset-info-row">
              <span className="asset-label">位置</span>
              <span>{asset.location}</span>
            </div>
          )}
          {asset.last_maintained_at && (
            <div className="asset-info-row">
              <span className="asset-label">上次維修</span>
              <span>{new Date(asset.last_maintained_at).toLocaleDateString('zh-TW')}</span>
            </div>
          )}
        </div>

        <form onSubmit={handleSubmit}>
          <div className="form-group">
            <label>問題標題 *</label>
            <input
              type="text"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="例如: 冷氣不冷"
              required
            />
          </div>

          <div className="form-group">
            <label>問題描述 *</label>
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="請描述遇到的問題..."
              rows={4}
              required
            />
          </div>

          <div className="form-group">
            <label>您的姓名 *</label>
            <input
              type="text"
              value={reporterName}
              onChange={(e) => setReporterName(e.target.value)}
              placeholder="您的姓名"
              required
            />
          </div>

          <div className="form-group">
            <label>聯絡方式</label>
            <input
              type="text"
              value={reporterContact}
              onChange={(e) => setReporterContact(e.target.value)}
              placeholder="電話或 Email（選填）"
            />
          </div>

          <button type="submit" className="btn-submit" disabled={submitting}>
            {submitting ? '送出中...' : '送出報修'}
          </button>
        </form>
      </div>
    </div>
  )
}
