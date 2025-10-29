import React, { useState } from 'react';

const BarcodeScanner = ({ onScan }) => {
  const [barcodeInput, setBarcodeInput] = useState('');
  const [isManualMode, setIsManualMode] = useState(true);

  const handleSubmit = (e) => {
    e.preventDefault();
    if (barcodeInput.trim()) {
      onScan(barcodeInput.trim());
      setBarcodeInput('');
    }
  };

  const handleKeyPress = (e) => {
    if (e.key === 'Enter') {
      handleSubmit(e);
    }
  };

  return (
    <div className="barcode-scanner">
      <div className="scanner-modes">
        <button 
          className={`mode-btn ${isManualMode ? 'active' : ''}`}
          onClick={() => setIsManualMode(true)}
        >
          Manual Entry
        </button>
        <button 
          className={`mode-btn ${!isManualMode ? 'active' : ''}`}
          onClick={() => setIsManualMode(false)}
        >
          Scanner Mode
        </button>
      </div>

      {isManualMode ? (
        <form onSubmit={handleSubmit} className="manual-entry">
          <div className="input-group">
            <input
              type="text"
              value={barcodeInput}
              onChange={(e) => setBarcodeInput(e.target.value)}
              onKeyPress={handleKeyPress}
              placeholder="Enter bin barcode or bin code"
              autoFocus
              className="barcode-input"
            />
            <button type="submit" className="scan-btn">
              Scan
            </button>
          </div>
          <p className="help-text">
            Enter barcode (MCN######) or bin code (BIN####)
          </p>
        </form>
      ) : (
        <div className="scanner-mode">
          <div className="scanner-area">
            <div className="scanner-frame">
              <div className="scanner-line"></div>
            </div>
            <p>Position barcode within the frame</p>
            <input
              type="text"
              value={barcodeInput}
              onChange={(e) => setBarcodeInput(e.target.value)}
              onKeyPress={handleKeyPress}
              className="hidden-scanner-input"
              autoFocus
              style={{ opacity: 0, position: 'absolute', left: '-9999px' }}
            />
          </div>
          <p className="help-text">
            Focus cursor in this area and scan barcode
          </p>
        </div>
      )}
    </div>
  );
};

export default BarcodeScanner;