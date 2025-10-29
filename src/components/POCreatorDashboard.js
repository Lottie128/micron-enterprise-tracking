import React, { useEffect, useState } from 'react';
import Select from './common/Select';
import Input from './common/Input';

const POCreatorDashboard = ({ user, onLogout }) => {
  const [clients, setClients] = useState([]);
  const [parts, setParts] = useState([]);
  const [items, setItems] = useState([]);
  const [poDate, setPoDate] = useState(() => new Date().toISOString().slice(0,10));
  const [deliveryDate, setDeliveryDate] = useState('');
  const [clientId, setClientId] = useState('');
  const [notes, setNotes] = useState('');
  const [message, setMessage] = useState('');

  const api = async (url, options={}) => {
    const res = await fetch(url, { ...options, headers: { 'Content-Type':'application/json','Authorization':`Bearer ${user.token}` }});
    return res.json();
  };

  useEffect(()=>{
    // load parts
    api('/backend/api/parts.php?action=list').then(d=>{ if(d.success){ setParts(d.parts.map(p=>({ value:p.id, label:`${p.part_number} - ${p.part_name}` })) ); }});
    // load clients
    api('/backend/api/clients.php').then(d=>{ if(d.success){ setClients(d.clients.map(c=>({ value:c.id, label:`${c.company_name} (${c.client_code})` })) ); }});
  },[]);

  const addItem = () => setItems([...items,{ part_id:'', quantity:0, unit_price:0 }]);
  const updateItem = (idx, field, value) => {
    const newItems=[...items]; newItems[idx][field]= field==='quantity'||field==='unit_price' ? Number(value):value; setItems(newItems);
  };
  const removeItem = (idx)=> setItems(items.filter((_,i)=>i!==idx));

  const createPO = async () => {
    if(!clientId || !items.length) { setMessage('Select client and add at least one item'); return; }
    const payload={ action:'create', client_id:Number(clientId), po_date:poDate, delivery_date:deliveryDate||null, notes, items };
    const d = await api('/backend/api/purchase_orders.php', { method:'POST', body: JSON.stringify(payload) });
    setMessage(d.success? `PO ${d.po_number} created` : d.message);
  };

  return (
    <div className="dashboard">
      <header className="dashboard-header">
        <h1>PO Creator</h1>
        <button onClick={onLogout} className="logout-btn">Logout</button>
      </header>
      {message && <div className="message">{message}</div>}

      <div className="section">
        <h2>Create Purchase Order</h2>
        <div className="form-row">
          <Input label="PO Date" type="date" value={poDate} onChange={setPoDate} required />
          <Input label="Delivery Date" type="date" value={deliveryDate} onChange={setDeliveryDate} />
          <Select label="Client" value={clientId} onChange={setClientId} options={clients} required />
        </div>
        <div className="form-group">
          <label>Notes</label>
          <textarea value={notes} onChange={e=>setNotes(e.target.value)} rows={3} />
        </div>

        <h3>Items</h3>
        {items.map((it,idx)=> (
          <div key={idx} className="form-row">
            <Select label="Part" value={it.part_id} onChange={v=>updateItem(idx,'part_id',v)} options={parts} required />
            <Input label="Quantity" type="number" value={it.quantity} onChange={v=>updateItem(idx,'quantity',v)} min={1} required />
            <Input label="Unit Price" type="number" value={it.unit_price} onChange={v=>updateItem(idx,'unit_price',v)} min={0} />
            <button className="danger" onClick={()=>removeItem(idx)}>Remove</button>
          </div>
        ))}
        <button onClick={addItem}>Add Item</button>
        <button onClick={createPO} className="primary">Create PO</button>
      </div>
    </div>
  );
};

export default POCreatorDashboard;