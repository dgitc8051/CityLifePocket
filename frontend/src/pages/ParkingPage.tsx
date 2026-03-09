import { useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { createSession } from '../services/parkingService'
import './ParkingPage.css'

interface LocationState {
    status: 'idle' | 'locating' | 'done' | 'error'
    lat: number | null
    lng: number | null
    accuracy: number | null
}

export function ParkingPage() {
    const navigate = useNavigate()
    const [location, setLocation] = useState<LocationState>({
        status: 'idle',
        lat: null,
        lng: null,
        accuracy: null,
    })
    const [floor, setFloor] = useState('')
    const [zone, setZone] = useState('')
    const [note, setNote] = useState('')
    const [photo, setPhoto] = useState<string | null>(null)
    const [saved, setSaved] = useState(false)
    const [saving, setSaving] = useState(false)

    const isUnderground = location.accuracy !== null && location.accuracy > 30

    const handleLocate = useCallback(() => {
        if (!navigator.geolocation) {
            setLocation((prev) => ({ ...prev, status: 'error' }))
            return
        }
        setLocation((prev) => ({ ...prev, status: 'locating' }))

        // Take best of multiple readings
        let bestAccuracy = Infinity
        let attempts = 0
        const maxAttempts = 3

        const watchId = navigator.geolocation.watchPosition(
            (pos) => {
                attempts++
                if (pos.coords.accuracy < bestAccuracy) {
                    bestAccuracy = pos.coords.accuracy
                    setLocation({
                        status: 'done',
                        lat: pos.coords.latitude,
                        lng: pos.coords.longitude,
                        accuracy: pos.coords.accuracy,
                    })
                }
                if (attempts >= maxAttempts) {
                    navigator.geolocation.clearWatch(watchId)
                }
            },
            () => {
                setLocation((prev) => ({ ...prev, status: 'error' }))
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        )

        // Safety timeout
        setTimeout(() => navigator.geolocation.clearWatch(watchId), 15000)
    }, [])

    const handlePhoto = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0]
        if (file) {
            const reader = new FileReader()
            reader.onload = (ev) => setPhoto(ev.target?.result as string)
            reader.readAsDataURL(file)
        }
    }

    const handleSave = async () => {
        if (!location.lat || !location.lng) return
        setSaving(true)
        try {
            await createSession({
                lat: location.lat,
                lng: location.lng,
                accuracy: location.accuracy,
                floor,
                zone,
                note,
                photo,
                isUnderground,
            })
            setSaved(true)
        } finally {
            setSaving(false)
        }
    }

    if (saved) {
        return (
            <div className="parking-page page-enter">
                <div className="container">
                    <div className="save-success">
                        <div className="success-icon">✅</div>
                        <h2>停車位已記錄！</h2>
                        <p className="text-muted">放心去逛吧，找車時回來就好。</p>
                        <div className="success-actions">
                            <button className="btn btn-primary btn-lg" onClick={() => navigate('/parking/find')}>
                                🔍 找我的車
                            </button>
                            <button className="btn btn-ghost" onClick={() => navigate('/')}>
                                回首頁
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        )
    }

    return (
        <div className="parking-page page-enter">
            <div className="container">
                <header className="page-header">
                    <h1>🅿️ 記錄停車位</h1>
                    <p className="text-muted">按下按鈕記錄你的位置</p>
                </header>

                {/* Step 1: GPS */}
                <div className="step-card glass-card">
                    <div className="step-number">1</div>
                    <div className="step-content">
                        <h3>定位你的位置</h3>
                        {location.status === 'idle' && (
                            <button className="btn btn-primary btn-lg locate-btn" onClick={handleLocate}>
                                📍 我停好了
                            </button>
                        )}
                        {location.status === 'locating' && (
                            <div className="locating-indicator">
                                <div className="pulse-ring" />
                                <span>定位中...</span>
                            </div>
                        )}
                        {location.status === 'done' && (
                            <div className="location-result">
                                <div className="coord-display">
                                    <span>📍 {location.lat?.toFixed(6)}, {location.lng?.toFixed(6)}</span>
                                </div>
                                <div className={`accuracy-bar ${isUnderground ? 'low' : 'good'}`}>
                                    <span className="accuracy-label">
                                        精度：{location.accuracy?.toFixed(0)}m
                                        {isUnderground ? ' ⚠️ 精度較低' : ' ✅ 精度良好'}
                                    </span>
                                    <div
                                        className="accuracy-fill"
                                        style={{ width: `${Math.max(10, 100 - (location.accuracy || 0))}%` }}
                                    />
                                </div>
                                <button className="btn btn-ghost btn-sm" onClick={handleLocate}>
                                    重新定位
                                </button>
                            </div>
                        )}
                        {location.status === 'error' && (
                            <div className="location-error">
                                <p>❌ 無法取得位置，請確認已開啟定位權限。</p>
                                <button className="btn btn-ghost" onClick={handleLocate}>重試</button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Step 2: Underground helpers (shown if accuracy is low) */}
                {location.status === 'done' && (
                    <div className="step-card glass-card">
                        <div className="step-number">2</div>
                        <div className="step-content">
                            <h3>補充資訊 <span className="optional-tag">可跳過</span></h3>

                            <div className="form-group">
                                <label>樓層</label>
                                <div className="floor-selector">
                                    {['B1', 'B2', 'B3', 'B4', 'B5', '1F', '2F', '3F'].map((f) => (
                                        <button
                                            key={f}
                                            className={`floor-btn ${floor === f ? 'active' : ''}`}
                                            onClick={() => setFloor(f)}
                                        >
                                            {f}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div className="form-group">
                                <label>區域 / 車位號碼</label>
                                <input
                                    type="text"
                                    className="form-input"
                                    placeholder="例：A 區 / 第 23 號"
                                    value={zone}
                                    onChange={(e) => setZone(e.target.value)}
                                />
                            </div>

                            <div className="form-group">
                                <label>拍照記錄</label>
                                <label className="photo-upload">
                                    <input type="file" accept="image/*" capture="environment" onChange={handlePhoto} />
                                    {photo ? (
                                        <img src={photo} alt="停車照片" className="photo-preview" />
                                    ) : (
                                        <div className="photo-placeholder">
                                            <span className="photo-icon">📷</span>
                                            <span>拍張照幫助記憶</span>
                                        </div>
                                    )}
                                </label>
                            </div>

                            <div className="form-group">
                                <label>備註</label>
                                <input
                                    type="text"
                                    className="form-input"
                                    placeholder="例：靠近電梯旁邊"
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                />
                            </div>
                        </div>
                    </div>
                )}

                {/* Save Button */}
                {location.status === 'done' && (
                    <button className="btn btn-primary btn-lg save-btn" onClick={handleSave} disabled={saving}>
                        {saving ? '儲存中...' : '儲存停車位'}
                    </button>
                )}
            </div>
        </div>
    )
}
