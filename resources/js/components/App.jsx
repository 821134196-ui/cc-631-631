import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../api';
import CalendarPage from './CalendarPage';
import AdjustmentsPage from './AdjustmentsPage';
import ChannelsPage from './ChannelsPage';

const TABS = [
    { key: 'calendar', label: '门店日历' },
    { key: 'adjustments', label: '调整申请' },
    { key: 'channels', label: '渠道状态' },
];

export default function App() {
    const [stores, setStores] = useState([]);
    const [storeId, setStoreId] = useState(null);
    const [tab, setTab] = useState('calendar');
    const [toast, setToast] = useState(null);

    useEffect(() => {
        api.get('/api/stores').then((list) => {
            setStores(list);
            if (list.length && !storeId) setStoreId(list[0].id);
        }).catch((e) => showToast(e.message, 'error'));
    }, []);

    const showToast = useCallback((message, kind = 'ok') => {
        setToast({ message, kind });
        setTimeout(() => setToast(null), 4000);
    }, []);

    const store = stores.find((s) => s.id === storeId);

    return (
        <div className="page">
            <header className="topbar">
                <div className="brand">
                    <span className="brand-mark">⏱</span>
                    <div>
                        <h1>营业时间调整与渠道同步</h1>
                        <p>一次申请 · 总部审批 · 官网 / 店内屏 / 外卖平台同步发布</p>
                    </div>
                </div>
                <label className="store-picker">
                    门店
                    <select value={storeId ?? ''} onChange={(e) => setStoreId(Number(e.target.value))}>
                        {stores.map((s) => (
                            <option key={s.id} value={s.id}>{s.name}（{s.code}）</option>
                        ))}
                    </select>
                </label>
            </header>

            <nav className="tabs">
                {TABS.map((t) => (
                    <button
                        key={t.key}
                        className={tab === t.key ? 'tab active' : 'tab'}
                        onClick={() => setTab(t.key)}
                    >
                        {t.label}
                    </button>
                ))}
            </nav>

            <main>
                {store && tab === 'calendar' && <CalendarPage store={store} />}
                {store && tab === 'adjustments' && <AdjustmentsPage store={store} showToast={showToast} />}
                {store && tab === 'channels' && <ChannelsPage store={store} showToast={showToast} />}
            </main>

            {toast && <div className={`toast toast-${toast.kind}`}>{toast.message}</div>}
        </div>
    );
}
