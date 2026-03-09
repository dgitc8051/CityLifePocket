import { BrowserRouter, Routes, Route } from 'react-router-dom'
import { BottomNav } from './components/BottomNav'
import { HomePage } from './pages/HomePage'
import { ParkingPage } from './pages/ParkingPage'
import { FindCarPage } from './pages/FindCarPage'
import { HistoryPage } from './pages/HistoryPage'
import './index.css'

function App() {
  return (
    <BrowserRouter>
      <div style={{ paddingBottom: '80px' }}>
        <Routes>
          <Route path="/" element={<HomePage />} />
          <Route path="/parking" element={<ParkingPage />} />
          <Route path="/parking/find" element={<FindCarPage />} />
          <Route path="/parking/history" element={<HistoryPage />} />
        </Routes>
      </div>
      <BottomNav />
    </BrowserRouter>
  )
}

export default App
