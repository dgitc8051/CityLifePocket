import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { getSessionHistory, type ParkingSession } from '../services/parkingService'
import './HistoryPage.css'

export function HistoryPage() {
    const navigate = useNavigate()
    const [sessions, setSessions] = useState<ParkingSession[]>([])

    useEffect(() => {
        getSessionHistory().then(setSessions)
    }, [])

    const completedSessions = sessions.filter((s) => s.completedAt)
    const activeSession = sessions.find((s) => !s.completedAt)

    const formatDuration = (start: string, end: string) => {
        const ms = new Date(end).getTime() - new Date(start).getTime()
        const hours = Math.floor(ms / 3600000)
        const minutes = Math.floor((ms % 3600000) / 60000)
        if (hours > 0) return `${hours} 小時 ${minutes} 分鐘`
        return `${minutes} 分鐘`
    }

    return (
        <div className="history-page page-enter">
            <div className="container">
                <header className="page-header">
                    <h1>📋 停車紀錄</h1>
                    <p className="text-muted">
                        共 {sessions.length} 筆紀錄
                    </p>
                </header>

                {/* Active Session */}
                {activeSession && (
                    <div className="active-session glass-card" onClick={() => navigate('/parking/find')}>
                        <div className="active-badge">
                            <span className="active-dot" />
                            進行中
                        </div>
                        <div className="session-info">
                            <span className="session-time">
                                {new Date(activeSession.startedAt).toLocaleString('zh-TW')}
                            </span>
                            <div className="session-details">
                                {activeSession.floor && <span className="detail-chip">{activeSession.floor}</span>}
                                {activeSession.zone && <span className="detail-chip">{activeSession.zone}</span>}
                                {activeSession.isUnderground && <span className="detail-chip underground">地下</span>}
                            </div>
                        </div>
                        <span className="session-arrow">→</span>
                    </div>
                )}

                {/* Completed Sessions */}
                {completedSessions.length > 0 ? (
                    <div className="session-list">
                        {completedSessions.map((session) => (
                            <div key={session.id} className="session-item glass-card">
                                <div className="session-item-header">
                                    <span className="session-date">
                                        {new Date(session.startedAt).toLocaleDateString('zh-TW', {
                                            month: 'short',
                                            day: 'numeric',
                                            weekday: 'short',
                                        })}
                                    </span>
                                    <span className="session-duration">
                                        ⏱️ {formatDuration(session.startedAt, session.completedAt!)}
                                    </span>
                                </div>
                                <div className="session-item-body">
                                    <span className="session-time-range">
                                        {new Date(session.startedAt).toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' })}
                                        {' → '}
                                        {new Date(session.completedAt!).toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' })}
                                    </span>
                                    <div className="session-details">
                                        {session.floor && <span className="detail-chip">{session.floor}</span>}
                                        {session.zone && <span className="detail-chip">{session.zone}</span>}
                                        {session.note && <span className="detail-chip note">{session.note}</span>}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    !activeSession && (
                        <div className="empty-state">
                            <div className="empty-icon">📋</div>
                            <h2>還沒有紀錄</h2>
                            <p className="text-muted">開始記錄你的停車位吧！</p>
                            <button className="btn btn-primary btn-lg" onClick={() => navigate('/parking')}>
                                📍 記錄停車位
                            </button>
                        </div>
                    )
                )}
            </div>
        </div>
    )
}
