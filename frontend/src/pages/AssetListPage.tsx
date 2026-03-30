import { useState, useEffect } from 'react'
import { QRCodeSVG } from 'qrcode.react'
import api from '../services/api'
import './AssetListPage.css'

interface Asset {
  id: number
  asset_number: string
  name: string
  type: string
  category: string
  location: string | null
  model: string | null
  is_active: boolean
  team: { name: string }
}

export function AssetListPage() {
  const [assets, setAssets] = useState<Asset[]>([])
  const [loading, setLoading] = useState(true)
  const [selectedAsset, setSelectedAsset] = useState<Asset | null>(null)

  useEffect(() => {
    api.get('/assets?per_page=50')
      .then((res) => setAssets(res.data.data))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  const reportUrl = (assetNumber: string) =>
    `${window.location.origin}/report/${assetNumber}`

  if (loading) return <div className="page-loading">載入中...</div>

  return (
    <div className="asset-list-page">
      <h1 className="page-title">資產管理</h1>

      <div className="asset-grid">
        {assets.map((asset) => (
          <div key={asset.id} className="asset-card" onClick={() => setSelectedAsset(asset)}>
            <div className="asset-card-header">
              <span className={`asset-type type-${asset.type}`}>
                {asset.type === 'equipment' ? '設備' : '軟體'}
              </span>
              <span className="asset-number">#{asset.asset_number}</span>
            </div>
            <div className="asset-card-name">{asset.name}</div>
            <div className="asset-card-info">
              {asset.location && <span>{asset.location}</span>}
              <span>{asset.team.name}</span>
            </div>
          </div>
        ))}
      </div>

      {/* QR Code Modal */}
      {selectedAsset && (
        <div className="modal-overlay" onClick={() => setSelectedAsset(null)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <h2>{selectedAsset.name}</h2>
            <p className="modal-subtitle">#{selectedAsset.asset_number}</p>
            <div className="qr-wrapper">
              <QRCodeSVG
                value={reportUrl(selectedAsset.asset_number)}
                size={200}
                level="M"
              />
            </div>
            <p className="qr-url">{reportUrl(selectedAsset.asset_number)}</p>
            <button className="modal-close" onClick={() => setSelectedAsset(null)}>關閉</button>
          </div>
        </div>
      )}
    </div>
  )
}
