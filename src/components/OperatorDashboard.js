import React, { useState, useEffect } from 'react';
import BarcodeScanner from './BarcodeScanner';

const OperatorDashboard = ({ user, onLogout }) => {
  const [scannedBin, setScannedBin] = useState(null);
  const [availableBins, setAvailableBins] = useState([]);
  const [movementForm, setMovementForm] = useState({
    quantity_in: '',
    quantity_rejected: 0,
    quantity_rework: 0,
    rejection_reason: '',
    rework_reason: '',
    notes: ''
  });
  const [rejectionReasons, setRejectionReasons] = useState([]);
  const [stages, setStages] = useState([]);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');

  const apiCall = async (url, options = {}) => {
    const response = await fetch(url, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${user.token}`,
        ...options.headers
      }
    });
    return response.json();
  };

  const handleBarcodeScanned = async (barcode) => {
    setLoading(true);
    try {
      const data = await apiCall(`/backend/api/bins.php?action=scan&barcode=${barcode}`);
      if (data.success) {
        setScannedBin(data.bin);
        setStages(data.stages);
        setRejectionReasons(data.rejection_reasons);
        setMovementForm({
          quantity_in: data.bin.current_quantity || '',
          quantity_rejected: 0,
          quantity_rework: 0,
          rejection_reason: '',
          rework_reason: '',
          notes: ''
        });
        setMessage('');
      } else {
        setMessage(`Error: ${data.message}`);
      }
    } catch (error) {
      setMessage(`Error scanning bin: ${error.message}`);
    }
    setLoading(false);
  };

  const getAvailableBins = async (stageId) => {
    try {
      const data = await apiCall(`/backend/api/bins.php?action=available&stage_id=${stageId}`);
      if (data.success) {
        setAvailableBins(data.bins);
      }
    } catch (error) {
      console.error('Failed to get available bins:', error);
    }
  };

  const handleMovementSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);

    try {
      const movementData = {
        action: 'movement',
        bin_id: scannedBin.id,
        po_item_id: scannedBin.po_id, // This needs to be corrected based on your data structure
        part_id: scannedBin.current_part_id,
        from_stage_id: scannedBin.current_stage_id,
        to_stage_id: parseInt(movementForm.to_stage_id),
        quantity_in: parseInt(movementForm.quantity_in),
        quantity_rejected: parseInt(movementForm.quantity_rejected),
        quantity_rework: parseInt(movementForm.quantity_rework),
        rejection_reason: movementForm.rejection_reason,
        rework_reason: movementForm.rework_reason,
        notes: movementForm.notes
      };

      const data = await apiCall('/backend/api/bins.php', {
        method: 'POST',
        body: JSON.stringify(movementData)
      });

      if (data.success) {
        setMessage('Movement recorded successfully!');
        setScannedBin(null);
        setMovementForm({
          quantity_in: '',
          quantity_rejected: 0,
          quantity_rework: 0,
          rejection_reason: '',
          rework_reason: '',
          notes: ''
        });
      } else {
        setMessage(`Error: ${data.message}`);
      }
    } catch (error) {
      setMessage(`Error recording movement: ${error.message}`);
    }
    setLoading(false);
  };

  const transferToBin = async (targetBinId) => {
    setLoading(true);
    try {
      const data = await apiCall('/backend/api/bins.php', {
        method: 'POST',
        body: JSON.stringify({
          action: 'transfer',
          from_bin_id: scannedBin.id,
          to_bin_id: targetBinId
        })
      });

      if (data.success) {
        setMessage('Transfer completed successfully!');
        setScannedBin(null);
        setAvailableBins([]);
      } else {
        setMessage(`Transfer failed: ${data.message}`);
      }
    } catch (error) {
      setMessage(`Transfer error: ${error.message}`);
    }
    setLoading(false);
  };

  const calculateOutputQuantity = () => {
    const input = parseInt(movementForm.quantity_in) || 0;
    const rejected = parseInt(movementForm.quantity_rejected) || 0;
    const rework = parseInt(movementForm.quantity_rework) || 0;
    return Math.max(0, input - rejected - rework);
  };

  return (
    <div className="dashboard">
      <header className="dashboard-header">
        <h1>Operator Dashboard</h1>
        <div className="user-info">
          <span>Welcome, {user.full_name}</span>
          <span>Machine: {user.machine_line || 'General'}</span>
          <button onClick={onLogout} className="logout-btn">Logout</button>
        </div>
      </header>

      <div className="dashboard-content">
        {message && (
          <div className={`message ${message.includes('Error') ? 'error' : 'success'}`}>
            {message}
          </div>
        )}

        <div className="operator-sections">
          <div className="section">
            <h2>Scan Bin</h2>
            <BarcodeScanner onScan={handleBarcodeScanned} />
            {loading && <div className="loading">Processing...</div>}
          </div>

          {scannedBin && (
            <div className="section">
              <h2>Bin Information</h2>
              <div className="bin-info">
                <p><strong>Bin Code:</strong> {scannedBin.bin_code}</p>
                <p><strong>Current Quantity:</strong> {scannedBin.current_quantity}</p>
                <p><strong>Part:</strong> {scannedBin.part_number} - {scannedBin.part_name}</p>
                <p><strong>Series:</strong> {scannedBin.series}</p>
                <p><strong>Current Stage:</strong> {scannedBin.stage_name}</p>
                <p><strong>PO Number:</strong> {scannedBin.po_number}</p>
                <p><strong>Client:</strong> {scannedBin.company_name}</p>
              </div>

              <form onSubmit={handleMovementSubmit} className="movement-form">
                <h3>Process Material</h3>
                
                <div className="form-row">
                  <div className="form-group">
                    <label>Quantity Input:</label>
                    <input
                      type="number"
                      value={movementForm.quantity_in}
                      onChange={(e) => setMovementForm({...movementForm, quantity_in: e.target.value})}
                      required
                      min="1"
                      max={scannedBin.current_quantity}
                    />
                  </div>
                  
                  <div className="form-group">
                    <label>To Stage:</label>
                    <select
                      value={movementForm.to_stage_id}
                      onChange={(e) => {
                        setMovementForm({...movementForm, to_stage_id: e.target.value});
                        getAvailableBins(e.target.value);
                      }}
                      required
                    >
                      <option value="">Select Stage</option>
                      {stages.map(stage => (
                        <option key={stage.id} value={stage.id}>
                          {stage.stage_name}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                <div className="form-row">
                  <div className="form-group">
                    <label>Rejected Pieces:</label>
                    <input
                      type="number"
                      value={movementForm.quantity_rejected}
                      onChange={(e) => setMovementForm({...movementForm, quantity_rejected: e.target.value})}
                      min="0"
                      max={movementForm.quantity_in}
                    />
                  </div>
                  
                  <div className="form-group">
                    <label>Rework Pieces:</label>
                    <input
                      type="number"
                      value={movementForm.quantity_rework}
                      onChange={(e) => setMovementForm({...movementForm, quantity_rework: e.target.value})}
                      min="0"
                      max={movementForm.quantity_in}
                    />
                  </div>
                </div>

                {parseInt(movementForm.quantity_rejected) > 0 && (
                  <div className="form-group">
                    <label>Rejection Reason:</label>
                    <select
                      value={movementForm.rejection_reason}
                      onChange={(e) => setMovementForm({...movementForm, rejection_reason: e.target.value})}
                      required
                    >
                      <option value="">Select Reason</option>
                      {rejectionReasons.map(reason => (
                        <option key={reason.id} value={reason.reason_code}>
                          {reason.reason_description}
                        </option>
                      ))}
                    </select>
                  </div>
                )}

                <div className="form-group">
                  <label>Notes:</label>
                  <textarea
                    value={movementForm.notes}
                    onChange={(e) => setMovementForm({...movementForm, notes: e.target.value})}
                    rows="3"
                  />
                </div>

                <div className="quantity-summary">
                  <strong>Output Quantity: {calculateOutputQuantity()} pieces</strong>
                </div>

                <button type="submit" disabled={loading} className="submit-btn">
                  {loading ? 'Processing...' : 'Record Movement'}
                </button>
              </form>

              {availableBins.length > 0 && (
                <div className="available-bins">
                  <h3>Available Bins for Transfer</h3>
                  <div className="bins-grid">
                    {availableBins.slice(0, 10).map(bin => (
                      <div key={bin.id} className="bin-card">
                        <p><strong>{bin.bin_code}</strong></p>
                        <p>Capacity: {bin.max_capacity}</p>
                        <p>Status: {bin.status}</p>
                        <button 
                          onClick={() => transferToBin(bin.id)}
                          disabled={loading}
                          className="transfer-btn"
                        >
                          Transfer Here
                        </button>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default OperatorDashboard;