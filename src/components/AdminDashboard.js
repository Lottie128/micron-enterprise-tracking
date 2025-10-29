import React, { useEffect, useState } from 'react';

const AdminDashboard = ({ user, onLogout }) => {
  const [stats, setStats] = useState(null);

  const api = async (url) => {
    const res = await fetch(url, { headers: { 'Authorization':`Bearer ${user.token}` }});
    return res.json();
  };

  useEffect(()=>{
    // Placeholder analytics; to be wired to analytics endpoints
    const load = async () => {
      const pos = await api('/backend/api/purchase_orders.php');
      setStats({ pos_count: (pos.purchase_orders||[]).length });
    };
    load();
  },[]);

  return (
    <div className="dashboard">
      <header className="dashboard-header">
        <h1>Admin Dashboard</h1>
        <button onClick={onLogout} className="logout-btn">Logout</button>
      </header>

      <div className="section">
        <h2>Overview</h2>
        <div className="cards">
          <div className="card"><h3>Total POs</h3><p>{stats?.pos_count||0}</p></div>
        </div>
      </div>
    </div>
  );
};

export default AdminDashboard;