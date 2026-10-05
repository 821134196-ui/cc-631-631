import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { StoreProvider, useStores } from './store-context';
import CalendarPage from './pages/CalendarPage';
import SubmitPage from './pages/SubmitPage';
import ReviewPage from './pages/ReviewPage';
import ChannelsPage from './pages/ChannelsPage';
import RecordsPage from './pages/RecordsPage';

const TABS = [
    { to: '/calendar', label: '门店日历' },
    { to: '/submit', label: '提交调整' },
    { to: '/review', label: '总部审核' },
    { to: '/channels', label: '渠道状态' },
    { to: '/records', label: '调整记录' },
];

function StorePicker() {
    const { stores, storeId, setStoreId } = useStores();
    return (
        <select
            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm"
            value={storeId ?? ''}
            onChange={(e) => setStoreId(Number(e.target.value))}
        >
            {stores.map((s) => (
                <option key={s.id} value={s.id}>{s.name}（常规 {s.regular_open}-{s.regular_close}）</option>
            ))}
        </select>
    );
}

function Layout() {
    return (
        <div className="min-h-screen bg-slate-100 text-slate-800">
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-3">
                    <h1 className="text-lg font-bold text-slate-900">营业时间调整与渠道同步</h1>
                    <StorePicker />
                    <nav className="ml-auto flex gap-1">
                        {TABS.map((t) => (
                            <NavLink
                                key={t.to}
                                to={t.to}
                                className={({ isActive }) =>
                                    `rounded-lg px-3 py-1.5 text-sm font-medium ${
                                        isActive ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                                    }`
                                }
                            >
                                {t.label}
                            </NavLink>
                        ))}
                    </nav>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-6">
                <Routes>
                    <Route path="/" element={<Navigate to="/calendar" replace />} />
                    <Route path="/calendar" element={<CalendarPage />} />
                    <Route path="/submit" element={<SubmitPage />} />
                    <Route path="/review" element={<ReviewPage />} />
                    <Route path="/channels" element={<ChannelsPage />} />
                    <Route path="/records" element={<RecordsPage />} />
                </Routes>
            </main>
        </div>
    );
}

createRoot(document.getElementById('root')).render(
    <BrowserRouter>
        <StoreProvider>
            <Layout />
        </StoreProvider>
    </BrowserRouter>,
);
