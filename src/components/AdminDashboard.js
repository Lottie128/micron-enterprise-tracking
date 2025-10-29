import React, { useEffect, useState } from 'react';

const AdminDashboard = ({ user, onLogout }) => {
  const [data, setData] = useState(null);

  const api = async (url) => {
    const res = await fetch(url, { headers: { 'Authorization':`Bearer ${user.token}` }});
    return res.json();
  };

  useEffect(()=>{ api('/backend/api/analytics.php').then(setData); },[]);

  return (
    <div className="dashboard">
      <header className="dashboard-header">
        <h1>Admin Dashboard</h1>
        <button onClick={onLogout} className="logout-btn">Logout</button>
      </header>

      <div className="section">
        <h2>Stage Throughput (30 days)</h2>
        <table className="table">
          <thead><tr><th>Stage</th><th>Passed</th><th>Rejected</th></tr></thead>
          <tbody>
            {data?.stage_throughput?.map((r,i)=> (
              <tr key={i}><td>{r.stage_name}</td><td>{r.passed||0}</td><td>{r.rejected||0}</td></tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="section">
        <h2>Operator Performance (30 days)</h2>
        <table className="table">
          <thead><tr><th>Operator</th><th>Stage</th><th>Passed</th><th>Rejected</th></tr></thead>
          <tbody>
            {data?.operator_performance?.map((r,i)=> (
              <tr key={i}><td>{r.full_name}</td><td>{r.stage_name}</td><td>{r.passed||0}</td><td>{r.rejected||0}</td></tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="section">
        <h2>Top Rejection Reasons (30 days)</h2>
        <table className="table">
          <thead><tr><th>Reason</th><th>Occurrences</th><th>Quantity</th></tr></thead>
          <tbody>
            {data?.rejection_reasons?.map((r,i)=> (
              <tr key={i}><td>{r.reason_description}</td><td>{r.occurrences}</td><td>{r.qty}</td></tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="section">
        <h2>PO Risk (Due vs Completed)</h2>
        <table className="table">
          <thead><tr><th>PO</th><th>Due</th><th>Ordered</th><th>Completed</th></tr></thead>
          <tbody>
            {data?.po_risk?.map((r,i)=> (
              <tr key={i}><td>{r.po_number}</td><td>{r.delivery_date||'—'}</td><td>{r.ordered||0}</td><td>{r.completed||0}</td></tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default AdminDashboard;