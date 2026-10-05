import React, { createContext, useContext, useEffect, useState } from 'react';
import { api } from './api';

const StoreContext = createContext(null);

export function StoreProvider({ children }) {
    const [stores, setStores] = useState([]);
    const [storeId, setStoreId] = useState(() => Number(localStorage.getItem('storeId')) || null);
    const [error, setError] = useState(null);

    useEffect(() => {
        api.get('/api/stores')
            .then((list) => {
                setStores(list);
                if (!list.some((s) => s.id === storeId)) {
                    setStoreId(list[0]?.id ?? null);
                }
            })
            .catch((e) => setError(e.message));
    }, []);

    useEffect(() => {
        if (storeId) localStorage.setItem('storeId', String(storeId));
    }, [storeId]);

    const store = stores.find((s) => s.id === storeId) ?? null;

    return (
        <StoreContext.Provider value={{ stores, store, storeId, setStoreId, error }}>
            {children}
        </StoreContext.Provider>
    );
}

export function useStores() {
    return useContext(StoreContext);
}
