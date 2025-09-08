import React from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter } from 'react-router-dom';
import './styles.css';
import App from './pages/App';
import { __bindAddEvent } from './api/client';
import { useEventStore } from './store/events';

const qc = new QueryClient();

createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={qc}>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </QueryClientProvider>
  </React.StrictMode>
);

// Bind after mount
(__bindAddEvent as any)((e:any)=>{ useEventStore.getState().add(e); });
