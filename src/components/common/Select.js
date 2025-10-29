import React from 'react';

const Select = ({label, value, onChange, options, placeholder='Select', required=false}) => (
  <div className="form-group">
    <label>{label}</label>
    <select value={value||''} onChange={e=>onChange(e.target.value)} required={required}>
      <option value="">{placeholder}</option>
      {options.map(opt=> (
        <option key={opt.value} value={opt.value}>{opt.label}</option>
      ))}
    </select>
  </div>
);

export default Select;