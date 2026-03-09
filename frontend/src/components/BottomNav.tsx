import { useLocation, useNavigate } from 'react-router-dom'
import './BottomNav.css'

const navItems = [
    { path: '/', icon: '🏠', label: '首頁' },
    { path: '/parking', icon: '🅿️', label: '記停車' },
    { path: '/parking/find', icon: '🔍', label: '找我的車' },
    { path: '/parking/history', icon: '📋', label: '紀錄' },
]

export function BottomNav() {
    const location = useLocation()
    const navigate = useNavigate()

    return (
        <nav className="bottom-nav">
            {navItems.map((item) => (
                <button
                    key={item.path}
                    className={`bottom-nav-item ${location.pathname === item.path ? 'active' : ''}`}
                    onClick={() => navigate(item.path)}
                >
                    <span className="nav-icon">{item.icon}</span>
                    <span>{item.label}</span>
                </button>
            ))}
        </nav>
    )
}
