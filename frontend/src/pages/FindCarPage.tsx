import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { getActiveSession, completeSession, type ParkingSession } from '../services/parkingService'
import './FindCarPage.css'

export function FindCarPage() {
    const navigate = useNavigate()
    const [session, setSession] = useState<ParkingSession | null>(null)
    const [loading, setLoading] = useState(true)
    const [step, setStep] = useState(0) // 0: overview, 1: navigate to entrance, 2: go to floor, 3: find spot
    const [completed, setCompleted] = useState(false)

    useEffect(() => {
        getActiveSession().then((active) => {
            setSession(active)
            setLoading(false)
        })
    }, [])

    const handleComplete = async () => {
        if (session) {
            await completeSession(session)
        }
        setCompleted(true)
    }

    const openNavigation = () => {
        if (session) {
            const url = `https://www.google.com/maps/dir/?api=1&destination=${session.lat},${session.lng}&travelmode=walking`
            window.open(url, '_blank')
        }
    }

    if (completed) {
        return (
            <div className="find-page page-enter">
                <div className="container">
                    <div className="complete-screen">
                        <div className="complete-icon">🎉</div>
                        <h2>找到車了！</h2>
                        <p className="text-muted">開車平安 🚗</p>
                        <button className="btn btn-primary btn-lg" onClick={() => navigate('/')}>
                            回首頁
                        </button>
                    </div>
                </div>
            </div>
        )
    }

    if (loading) {
        return (
            <div className="find-page page-enter">
                <div className="container">
                    <div className="empty-state">
                        <div className="locating-indicator"><div className="pulse-ring" /><span>載入中...</span></div>
                    </div>
                </div>
            </div>
        )
    }

    if (!session) {
        return (
            <div className="find-page page-enter">
                <div className="container">
                    <div className="empty-state">
                        <div className="empty-icon">🅿️</div>
                        <h2>目前沒有停車紀錄</h2>
                        <p className="text-muted">先記錄你的停車位吧！</p>
                        <button className="btn btn-primary btn-lg" onClick={() => navigate('/parking')}>
                            📍 記錄停車位
                        </button>
                    </div>
                </div>
            </div>
        )
    }

    const steps = [
        {
            icon: '🗺️',
            title: '總覽',
            desc: '你的停車資訊',
        },
        {
            icon: '🚶',
            title: '回到入口',
            desc: '導航到停車場入口位置',
        },
        ...(session.floor
            ? [
                {
                    icon: '⬇️',
                    title: `前往 ${session.floor}`,
                    desc: '搭電梯或走樓梯到你停的樓層',
                },
            ]
            : []),
        {
            icon: '📸',
            title: '找到車位',
            desc: session.zone
                ? `尋找 ${session.zone}`
                : '根據照片和備註找到你的車',
        },
    ]

    return (
        <div className="find-page page-enter">
            <div className="container">
                <header className="page-header">
                    <h1>🔍 找我的車</h1>
                    <p className="text-muted">
                        停車時間：{new Date(session.startedAt).toLocaleString('zh-TW')}
                    </p>
                </header>

                {/* Three-step progress */}
                <div className="step-progress">
                    {steps.map((s, i) => (
                        <button
                            key={i}
                            className={`step-dot ${step === i ? 'active' : ''} ${step > i ? 'done' : ''}`}
                            onClick={() => setStep(i)}
                        >
                            <span className="step-dot-icon">{step > i ? '✓' : s.icon}</span>
                            <span className="step-dot-label">{s.title}</span>
                        </button>
                    ))}
                </div>

                {/* Step Content */}
                <div className="step-panel glass-card">
                    <h2>{steps[step].icon} {steps[step].title}</h2>
                    <p className="text-muted">{steps[step].desc}</p>

                    {step === 0 && (
                        <div className="overview-info">
                            {session.accuracy && (
                                <div className="info-row">
                                    <span className="info-label">GPS 精度</span>
                                    <span className={`info-value ${session.isUnderground ? 'warn' : ''}`}>
                                        {session.accuracy.toFixed(0)}m
                                        {session.isUnderground && ' ⚠️ 地下停車場'}
                                    </span>
                                </div>
                            )}
                            {session.floor && (
                                <div className="info-row">
                                    <span className="info-label">樓層</span>
                                    <span className="info-value">{session.floor}</span>
                                </div>
                            )}
                            {session.zone && (
                                <div className="info-row">
                                    <span className="info-label">區域/車位</span>
                                    <span className="info-value">{session.zone}</span>
                                </div>
                            )}
                            {session.note && (
                                <div className="info-row">
                                    <span className="info-label">備註</span>
                                    <span className="info-value">{session.note}</span>
                                </div>
                            )}
                            {session.photo && (
                                <img src={session.photo} alt="停車照片" className="overview-photo" />
                            )}
                            <button className="btn btn-primary btn-lg" onClick={() => setStep(1)} style={{ marginTop: '16px', width: '100%' }}>
                                開始找車 →
                            </button>
                        </div>
                    )}

                    {step === 1 && (
                        <div className="nav-step">
                            <button className="btn btn-primary btn-lg" onClick={openNavigation} style={{ width: '100%' }}>
                                🗺️ 開啟 Google Maps 導航
                            </button>
                            <p className="nav-hint">
                                {session.isUnderground
                                    ? '⚠️ GPS 精度較低，導航到入口後請使用照片和樓層資訊'
                                    : '✅ GPS 精度良好，可直接導航到車旁'}
                            </p>
                            <button className="btn btn-ghost" onClick={() => setStep(step + 1)} style={{ width: '100%' }}>
                                到入口了，下一步 →
                            </button>
                        </div>
                    )}

                    {step > 1 && step < steps.length - 1 && (
                        <div className="floor-step">
                            <div className="floor-visual">
                                <span className="floor-big">{session.floor}</span>
                            </div>
                            <button className="btn btn-ghost" onClick={() => setStep(step + 1)} style={{ width: '100%' }}>
                                到了，下一步 →
                            </button>
                        </div>
                    )}

                    {step === steps.length - 1 && (
                        <div className="final-step">
                            {session.photo && (
                                <img src={session.photo} alt="停車照片" className="find-photo" />
                            )}
                            {session.zone && (
                                <div className="zone-display">
                                    <span className="zone-label">找這個位置</span>
                                    <span className="zone-value">{session.zone}</span>
                                </div>
                            )}
                            {session.note && (
                                <div className="note-display">
                                    💡 {session.note}
                                </div>
                            )}
                            <button className="btn btn-primary btn-lg" onClick={handleComplete} style={{ width: '100%', marginTop: '16px' }}>
                                🎉 我找到車了！
                            </button>
                        </div>
                    )}
                </div>

                {/* Quick nav button */}
                <button className="btn btn-ghost quick-nav-btn" onClick={openNavigation}>
                    🗺️ 快速開啟導航
                </button>
            </div>
        </div>
    )
}
