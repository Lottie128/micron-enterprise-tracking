import React, { useEffect, useState } from 'react';

const ClientDashboard = ({ user, onLogout }) => {
  const [pos, setPos] = useState([]);
  const [message, setMessage] = useState('');

  const api = async (url) => {
    const res = await fetch(url, { headers: { 'Authorization':`Bearer ${user.token}` }});
    return res.json();
  };

  useEffect(()=>{ api('/backend/api/purchase_orders.php').then(d=>{ if(d.success) setPos(d.purchase_orders||[]); }); },[]);

  return (
    <div className="dashboard">
      <header className="dashboard-header">
        <h1>Client Dashboard</h1>
        <button onClick={onLogout} className="logout-btn">Logout</button>
      </header>
      {message && <div className="message">{message}</div>}

      <div className="section">
        <h2>Your Purchase Orders</h2>
        <table className="table">
          <thead>
            <tr>
              <th>PO Number</th><th>Date</th><th>Items</th><th>Ordered Qty</th><th>Completed</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            {pos.map(po=> (
              <tr key={po.id}>
                <td>{po.po_number}</td>
                <td>{po.po_date}</td>
                <td>{po.total_items}</td>
                <td>{po.total_quantity}</td>
                <td>{po.completed_quantity}</td>
                <td>{po.status}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default ClientDashboard;