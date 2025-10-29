import React from 'react';

const Input = ({label, type='text', value, onChange, min, max, required=false, placeholder=''}) => (
  <div className="form-group">
    <label>{label}</label>
    <input type={type} value={value||''} onChange={e=>onChange(e.target.value)} min={min} max={max} required={required} placeholder={placeholder} />
  </div>
);

export default Input;