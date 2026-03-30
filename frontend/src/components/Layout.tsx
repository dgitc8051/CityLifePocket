import { NavLink, Outlet, Navigate } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import './Layout.css'

export function Layout() {
  const { user, logout, loading } = useAuth()

  if (loading) return <div className="loading">載入中...</div>
  if (!user) return <Navigate to="/login" />

  return (
    <div className="layout">
      <aside className="sidebar">
        <div className="sidebar-brand">FixFlow</div>
        <nav className="sidebar-nav">
          <NavLink to="/dashboard" className="nav-link">Dashboard</NavLink>
          <NavLink to="/incidents" className="nav-link">事件管理</NavLink>
          <NavLink to="/assets" className="nav-link">資產管理</NavLink>
          <NavLink to="/oncall" className="nav-link">值班表</NavLink>
        </nav>
        <div className="sidebar-footer">
          <div className="sidebar-user">{user.name}</div>
          <button className="btn-logout" onClick={logout}>登出</button>
        </div>
      </aside>
      <main className="main-content">
        <Outlet />
      </main>
    </div>
  )
}
