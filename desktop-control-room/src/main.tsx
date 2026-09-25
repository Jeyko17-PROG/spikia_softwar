import React from 'react';
import ReactDOM from 'react-dom/client';
import { MasterDashboard } from './components/MasterDashboard';
import './index.css';

ReactDOM.createRoot(document.getElementById('root') as HTMLElement).render(
    <React.StrictMode>
        <MasterDashboard />
    </React.StrictMode>,
);
