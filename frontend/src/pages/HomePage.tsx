import { useNavigate } from 'react-router-dom'
import './HomePage.css'

export function HomePage() {
    const navigate = useNavigate()

    return (
        <div className="home-page page-enter">
            {/* Hero Section */}
            <header className="home-hero">
                <div className="hero-bg-orb hero-orb-1" />
                <div className="hero-bg-orb hero-orb-2" />
                <div className="container">
                    <div className="hero-content">
                        <span className="hero-emoji">🏙️</span>
                        <h1 className="hero-title">
                            <span className="gradient-text">City Life</span>
                            <br />
                            <span className="hero-title-sub">Pocket</span>
                        </h1>
                        <p className="hero-desc">城市生活口袋助手</p>
                    </div>
                </div>
            </header>

            {/* Feature Cards */}
            <section className="container home-features">
                {/* Parking - Active */}
                <div
                    className="feature-card glass-card"
                    onClick={() => navigate('/parking')}
                >
                    <div className="feature-card-icon parking-icon">🅿️</div>
                    <div className="feature-card-body">
                        <div className="feature-card-header">
                            <h2>停車找車</h2>
                            <span className="badge badge-active">● 已上線</span>
                        </div>
                        <p className="feature-card-desc">
                            一鍵記錄停車位置，三段式找車指引，<br />
                            地下停車場也不怕迷路。
                        </p>
                        <div className="feature-card-actions">
                            <button
                                className="btn btn-primary"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    navigate('/parking')
                                }}
                            >
                                記錄停車位
                            </button>
                            <button
                                className="btn btn-ghost"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    navigate('/parking/find')
                                }}
                            >
                                找我的車
                            </button>
                        </div>
                    </div>
                </div>

                {/* Trash - Coming Soon */}
                <div className="feature-card glass-card coming-soon">
                    <div className="feature-card-icon trash-icon">🚛</div>
                    <div className="feature-card-body">
                        <div className="feature-card-header">
                            <h2>垃圾清運查詢</h2>
                            <span className="badge badge-coming">🔜 即將推出</span>
                        </div>
                        <p className="feature-card-desc">
                            全台清運時刻表查詢，台北/新北即時追蹤，<br />
                            再也不錯過垃圾車。
                        </p>
                        <div className="feature-card-actions">
                            <button className="btn btn-ghost" disabled>
                                敬請期待
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    )
}
