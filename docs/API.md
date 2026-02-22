# OGITECH COOP – REST API Documentation

> **Version:** 1.0
> **Base URL:** `https://your-domain.com/api/v1`
> **Auth:** Laravel Sanctum (Bearer Token)
> **Date:** February 2026

---

## Table of Contents

1. [Overview](#overview)
2. [Getting Started (Expo + Redux)](#getting-started-expo--redux)
3. [Authentication Flow](#authentication-flow)
4. [API Endpoints – Auth](#api-endpoints)
    - [Auth – Public](#auth--public)
    - [Auth – Protected](#auth--protected)
    - [Lookup Data](#lookup-data)
5. [Error Handling](#error-handling)
6. [API Endpoints – Member](#api-endpoints--member)
    - [Dashboard](#member--dashboard)
    - [Savings](#member--savings)
    - [Savings Settings](#member--savings-settings)
    - [Shares](#member--shares)
    - [Loans](#member--loans)
    - [Guarantor Requests](#member--guarantor-requests)
    - [Withdrawals](#member--withdrawals)
    - [Transactions / Passbook](#member--transactions--passbook)
    - [Commodities](#member--commodities)
    - [Notifications](#member--notifications)
    - [Financial Summary](#member--financial-summary)
    - [Profile](#member--profile)
    - [Resources](#member--resources)
7. [Available Modules – Admin (Upcoming)](#available-modules--admin-upcoming)
8. [React Native Integration Guide (Redux)](#react-native-integration-guide-redux)
9. [Redux Cheat Sheet for Interviews](#redux-cheat-sheet-for-interviews)

---

## Overview

The OGITECH Cooperative Society API powers a mobile app that allows:

| Role       | Capabilities                                                                                                                                                            |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Member** | View dashboard, savings, shares, loans, passbook/transactions, withdrawals, commodities, guarantor requests, notifications, profile management                          |
| **Admin**  | Manage members, entrance fees, savings/types, shares/types, loans/types, repayments, transactions, reports, financial summaries, commodities, FAQs, roles & permissions |

All API responses follow a consistent envelope:

```json
{
  "success": true,
  "message": "Human-readable message",
  "data": { ... }
}
```

On validation errors (422):

```json
{
    "success": false,
    "message": "Validation failed.",
    "errors": {
        "field_name": ["Error message"]
    }
}
```

---

## Getting Started (Expo + Redux)

### 1. Create the project (Expo)

```bash
npx create-expo-app@latest OgitechCoop --template blank
cd OgitechCoop
```

### 2. Install dependencies

```bash
# Navigation
npx expo install @react-navigation/native @react-navigation/native-stack
npx expo install react-native-screens react-native-safe-area-context

# Redux Toolkit + Persist
npm install @reduxjs/toolkit react-redux redux-persist
npx expo install @react-native-async-storage/async-storage

# HTTP client
npm install axios

# Image picker (for registration)
npx expo install expo-image-picker
```

### 3. Configure Axios instance

Create `src/api/client.js`:

```javascript
import axios from "axios";
import { store } from "../store";
import { logout } from "../store/slices/authSlice";

const API_BASE_URL = "https://your-domain.com/api/v1";

const apiClient = axios.create({
    baseURL: API_BASE_URL,
    timeout: 30000,
    headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
    },
});

// Attach token from Redux store to every request
apiClient.interceptors.request.use((config) => {
    const { token } = store.getState().auth;
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

// Handle 401 globally (token expired / revoked)
apiClient.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            store.dispatch(logout()); // clears Redux + persisted state
        }
        return Promise.reject(error);
    },
);

export default apiClient;
```

> **Key difference from Context API:** The token is read synchronously from the Redux store instead of awaiting AsyncStorage. Redux Persist ensures the token survives app restarts.

### 4. Redux Store setup

Create `src/store/index.js`:

```javascript
import { configureStore, combineReducers } from "@reduxjs/toolkit";
import {
    persistStore,
    persistReducer,
    FLUSH,
    REHYDRATE,
    PAUSE,
    PERSIST,
    PURGE,
    REGISTER,
} from "redux-persist";
import AsyncStorage from "@react-native-async-storage/async-storage";
import authReducer from "./slices/authSlice";
import lookupReducer from "./slices/lookupSlice";
// Phase 2 – Member slices (see "Member Redux Slices" section below for full code)
import dashboardReducer from "./slices/dashboardSlice";
import savingsReducer from "./slices/savingsSlice";
import sharesReducer from "./slices/sharesSlice";
import loansReducer from "./slices/loansSlice";
import withdrawalsReducer from "./slices/withdrawalsSlice";
import transactionsReducer from "./slices/transactionsSlice";
import commoditiesReducer from "./slices/commoditiesSlice";
import notificationsReducer from "./slices/notificationsSlice";
import financialReducer from "./slices/financialSlice";

const persistConfig = {
    key: "root",
    storage: AsyncStorage,
    whitelist: ["auth"], // only persist auth (token, user, role)
};

const rootReducer = combineReducers({
    auth: authReducer,
    lookup: lookupReducer,
    dashboard: dashboardReducer,
    savings: savingsReducer,
    shares: sharesReducer,
    loans: loansReducer,
    withdrawals: withdrawalsReducer,
    transactions: transactionsReducer,
    commodities: commoditiesReducer,
    notifications: notificationsReducer,
    financial: financialReducer,
});

const persistedReducer = persistReducer(persistConfig, rootReducer);

export const store = configureStore({
    reducer: persistedReducer,
    middleware: (getDefaultMiddleware) =>
        getDefaultMiddleware({
            serializableCheck: {
                ignoredActions: [
                    FLUSH,
                    REHYDRATE,
                    PAUSE,
                    PERSIST,
                    PURGE,
                    REGISTER,
                ],
            },
        }),
});

export const persistor = persistStore(store);
```

### 5. Auth Slice (Redux Toolkit – `createSlice` + `createAsyncThunk`)

Create `src/store/slices/authSlice.js`:

```javascript
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import apiClient from "../../api/client";
import { Platform } from "react-native";

// ─── Async Thunks ────────────────────────────────

export const loginUser = createAsyncThunk(
    "auth/loginUser",
    async ({ email, password }, { rejectWithValue }) => {
        try {
            const response = await apiClient.post("/login", {
                email,
                password,
                device_name: Platform.OS,
            });
            return response.data.data; // { token, role, user }
        } catch (error) {
            return rejectWithValue(
                error.response?.data || { message: "Network error" },
            );
        }
    },
);

export const registerUser = createAsyncThunk(
    "auth/registerUser",
    async (formData, { rejectWithValue }) => {
        try {
            const response = await apiClient.post("/register", formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });
            return response.data; // { success, message, data: { user } }
        } catch (error) {
            return rejectWithValue(
                error.response?.data || { message: "Network error" },
            );
        }
    },
);

export const fetchProfile = createAsyncThunk(
    "auth/fetchProfile",
    async (_, { rejectWithValue }) => {
        try {
            const response = await apiClient.get("/me");
            return response.data.data; // user object
        } catch (error) {
            return rejectWithValue(
                error.response?.data || { message: "Network error" },
            );
        }
    },
);

export const changePassword = createAsyncThunk(
    "auth/changePassword",
    async (
        { currentPassword, newPassword, confirmPassword },
        { rejectWithValue },
    ) => {
        try {
            const response = await apiClient.post("/change-password", {
                current_password: currentPassword,
                password: newPassword,
                password_confirmation: confirmPassword,
                device_name: Platform.OS,
            });
            return response.data.data; // { token }
        } catch (error) {
            return rejectWithValue(
                error.response?.data || { message: "Network error" },
            );
        }
    },
);

export const forgotPassword = createAsyncThunk(
    "auth/forgotPassword",
    async ({ email }, { rejectWithValue }) => {
        try {
            const response = await apiClient.post("/forgot-password", {
                email,
            });
            return response.data;
        } catch (error) {
            return rejectWithValue(
                error.response?.data || { message: "Network error" },
            );
        }
    },
);

export const logoutUser = createAsyncThunk(
    "auth/logoutUser",
    async (_, { dispatch }) => {
        try {
            await apiClient.post("/logout");
        } finally {
            dispatch(logout());
        }
    },
);

// ─── Slice ───────────────────────────────────────

const authSlice = createSlice({
    name: "auth",
    initialState: {
        user: null,
        token: null,
        role: null, // 'admin' | 'member' | null
        loading: false,
        error: null,
        isAuthenticated: false,
    },
    reducers: {
        /** Synchronous logout – clears all auth state */
        logout(state) {
            state.user = null;
            state.token = null;
            state.role = null;
            state.isAuthenticated = false;
            state.error = null;
        },
        /** Clear error messages (e.g. when navigating away) */
        clearError(state) {
            state.error = null;
        },
    },
    extraReducers: (builder) => {
        // ── Login ──
        builder
            .addCase(loginUser.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(loginUser.fulfilled, (state, action) => {
                state.loading = false;
                state.token = action.payload.token;
                state.role = action.payload.role;
                state.user = action.payload.user;
                state.isAuthenticated = true;
            })
            .addCase(loginUser.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload;
            })

            // ── Register ──
            .addCase(registerUser.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(registerUser.fulfilled, (state) => {
                state.loading = false;
                // User still needs admin approval, so don't set token
            })
            .addCase(registerUser.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload;
            })

            // ── Fetch Profile ──
            .addCase(fetchProfile.fulfilled, (state, action) => {
                state.user = action.payload;
            })

            // ── Change Password ──
            .addCase(changePassword.fulfilled, (state, action) => {
                state.token = action.payload.token;
            })

            // ── Forgot Password ──
            .addCase(forgotPassword.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(forgotPassword.fulfilled, (state) => {
                state.loading = false;
            })
            .addCase(forgotPassword.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload;
            });
    },
});

export const { logout, clearError } = authSlice.actions;

// ─── Selectors ───────────────────────────────────
export const selectAuth = (state) => state.auth;
export const selectUser = (state) => state.auth.user;
export const selectToken = (state) => state.auth.token;
export const selectRole = (state) => state.auth.role;
export const selectIsAdmin = (state) => state.auth.role === "admin";
export const selectIsAuthenticated = (state) => state.auth.isAuthenticated;
export const selectAuthLoading = (state) => state.auth.loading;
export const selectAuthError = (state) => state.auth.error;

export default authSlice.reducer;
```

### 6. Lookup Slice (states, faculties for registration dropdowns)

Create `src/store/slices/lookupSlice.js`:

```javascript
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import apiClient from "../../api/client";

export const fetchStates = createAsyncThunk("lookup/fetchStates", async () => {
    const response = await apiClient.get("/states");
    return response.data.data;
});

export const fetchLgas = createAsyncThunk(
    "lookup/fetchLgas",
    async (stateId) => {
        const response = await apiClient.get(`/states/${stateId}/lgas`);
        return response.data.data;
    },
);

export const fetchFaculties = createAsyncThunk(
    "lookup/fetchFaculties",
    async () => {
        const response = await apiClient.get("/faculties");
        return response.data.data;
    },
);

export const fetchDepartments = createAsyncThunk(
    "lookup/fetchDepartments",
    async (facultyId) => {
        const response = await apiClient.get(
            `/faculties/${facultyId}/departments`,
        );
        return response.data.data;
    },
);

const lookupSlice = createSlice({
    name: "lookup",
    initialState: {
        states: [],
        lgas: [],
        faculties: [],
        departments: [],
        loading: false,
    },
    reducers: {
        clearLgas(state) {
            state.lgas = [];
        },
        clearDepartments(state) {
            state.departments = [];
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchStates.fulfilled, (state, action) => {
                state.states = action.payload;
            })
            .addCase(fetchLgas.fulfilled, (state, action) => {
                state.lgas = action.payload;
            })
            .addCase(fetchFaculties.fulfilled, (state, action) => {
                state.faculties = action.payload;
            })
            .addCase(fetchDepartments.fulfilled, (state, action) => {
                state.departments = action.payload;
            });
    },
});

export const { clearLgas, clearDepartments } = lookupSlice.actions;
export default lookupSlice.reducer;
```

---

## Authentication Flow

```
┌─────────────────┐       POST /login           ┌──────────────────┐
│  React Native    │  ──────────────────────────►│  Laravel API     │
│  (Expo + Redux)  │  { email, password,         │  (Sanctum)       │
│                  │    device_name }             │                  │
│                  │◄──────────────────────────  │                  │
│                  │  { token, role, user }       │                  │
└────────┬────────┘                              └──────────────────┘
         │
         │  dispatch(loginUser.fulfilled)
         │  → Redux store: { token, user, role }
         │  → Redux Persist → AsyncStorage (survives restart)
         │
         │  All subsequent requests:
         │  Axios interceptor reads store.auth.token
         │  Authorization: Bearer <token>
         │
         ▼
┌─────────────────┐       GET /me               ┌──────────────────┐
│  Protected       │  ──────────────────────────►│  Sanctum checks  │
│  Screen          │◄──────────────────────────  │  token validity  │
│  (useSelector)   │  { user profile data }      │                  │
└─────────────────┘                              └──────────────────┘
```

### Redux Data Flow (Interview-Ready Explanation)

```
User taps "Login"
    │
    ▼
dispatch(loginUser({ email, password }))
    │
    │  createAsyncThunk runs:
    │    1. Sets state.loading = true     (pending)
    │    2. POST /api/v1/login
    │    3. On success → fulfilled        (fulfilled)
    │       state.token = response.token
    │       state.user  = response.user
    │       state.role  = response.role
    │       state.isAuthenticated = true
    │    4. Redux Persist auto-saves to AsyncStorage
    │    5. On error → rejected           (rejected)
    │       state.error = error payload
    │
    ▼
Navigation re-renders via useSelector(selectIsAuthenticated)
    │
    ▼
MemberStack or AdminStack shown based on selectRole
```

### Roles & Abilities

| Role     | Token Abilities       | Access                                                   |
| -------- | --------------------- | -------------------------------------------------------- |
| `member` | `["member"]`          | `/api/v1/member/*` and shared routes                     |
| `admin`  | `["admin", "member"]` | `/api/v1/admin/*`, `/api/v1/member/*`, and shared routes |

---

## API Endpoints

### Auth – Public

These endpoints do **not** require a Bearer token.

---

#### `POST /api/v1/login`

Login with email or member number.

**Request:**

```json
{
    "email": "john@example.com",
    "password": "secret123",
    "device_name": "android"
}
```

> `email` field accepts either an email address **or** a member number (e.g. `OASCMS-001`).
> `device_name` is optional (defaults to `"mobile"`). Use `"ios"` or `"android"` to manage per-device tokens.

**Success Response (200):**

```json
{
    "success": true,
    "message": "Login successful.",
    "data": {
        "token": "1|abc123def456...",
        "role": "member",
        "user": {
            "id": 1,
            "title": "Mr",
            "surname": "Doe",
            "firstname": "John",
            "othername": null,
            "email": "john@example.com",
            "phone_number": "08012345678",
            "member_no": "OASCMS-001",
            "staff_no": "STF-001",
            "dob": "1990-05-15",
            "nationality": "Nigerian",
            "home_address": "123 Main Street, Ijebu Ode",
            "state": "Ogun",
            "lga": "Ijebu Ode",
            "faculty": "Engineering",
            "department": "Computer Science",
            "nok": "Jane Doe",
            "nok_relationship": "Wife",
            "nok_phone": "08087654321",
            "nok_address": "456 Other Street",
            "monthly_savings": 5000,
            "share_subscription": 10000,
            "month_commence": "January",
            "member_image": "https://your-domain.com/storage/members/photo.jpg",
            "signature_image": "https://your-domain.com/storage/signatures/sig.jpg",
            "is_admin": false,
            "admin_sign": "Yes",
            "status": "active",
            "date_join": "2024-01-15",
            "created_at": "2024-01-15T10:30:00+01:00"
        }
    }
}
```

**Error – Invalid credentials (422):**

```json
{
    "message": "The provided credentials do not match our records.",
    "errors": {
        "email": ["The provided credentials do not match our records."]
    }
}
```

**Error – Pending approval (403):**

```json
{
    "success": false,
    "message": "Your account is pending admin approval."
}
```

---

#### `POST /api/v1/register`

Register a new member. Send as **`multipart/form-data`** (because of image uploads).

**Form Fields:**

| Field                        | Type    | Required | Notes                                       |
| ---------------------------- | ------- | -------- | ------------------------------------------- |
| `title`                      | string  | ✅       | Mr, Mrs, Dr, Prof, etc.                     |
| `surname`                    | string  | ✅       | Max 100 chars                               |
| `firstname`                  | string  | ✅       | Max 100 chars                               |
| `othername`                  | string  | ❌       | Max 100 chars                               |
| `dob`                        | date    | ✅       | Format: `YYYY-MM-DD`                        |
| `nationality`                | string  | ✅       | e.g. "Nigerian"                             |
| `home_address`               | string  | ✅       | Full residential address                    |
| `phone_number`               | string  | ✅       |                                             |
| `email`                      | email   | ✅       | Must be unique                              |
| `state_id`                   | integer | ✅       | From `/states` endpoint                     |
| `lga_id`                     | integer | ✅       | From `/states/{id}/lgas` endpoint           |
| `staff_no`                   | string  | ✅       | Must be unique                              |
| `faculty_id`                 | integer | ✅       | From `/faculties` endpoint                  |
| `department_id`              | integer | ✅       | From `/faculties/{id}/departments` endpoint |
| `nok`                        | string  | ✅       | Next of kin full name                       |
| `nok_relationship`           | string  | ✅       | e.g. "Wife", "Brother"                      |
| `nok_address`                | string  | ✅       |                                             |
| `nok_phone`                  | string  | ✅       |                                             |
| `monthly_savings`            | number  | ✅       | Min: 0                                      |
| `share_subscription`         | number  | ✅       | Min: 0                                      |
| `month_commence`             | string  | ✅       | e.g. "January"                              |
| `member_image`               | file    | ✅       | JPG/PNG, max 2MB                            |
| `signature_image`            | file    | ✅       | JPG/PNG, max 2MB                            |
| `password`                   | string  | ✅       | Min 8 characters                            |
| `password_confirmation`      | string  | ✅       | Must match `password`                       |
| `salary_deduction_agreement` | boolean | ✅       | Must be `true` / `1`                        |
| `membership_declaration`     | boolean | ✅       | Must be `true` / `1`                        |

**React Native example (FormData):**

```javascript
const formData = new FormData();
formData.append("title", "Mr");
formData.append("surname", "Doe");
formData.append("firstname", "John");
formData.append("dob", "1990-05-15");
formData.append("nationality", "Nigerian");
formData.append("home_address", "123 Main Street");
formData.append("phone_number", "08012345678");
formData.append("email", "john@example.com");
formData.append("state_id", 1);
formData.append("lga_id", 5);
formData.append("staff_no", "STF-001");
formData.append("faculty_id", 1);
formData.append("department_id", 3);
formData.append("nok", "Jane Doe");
formData.append("nok_relationship", "Wife");
formData.append("nok_address", "456 Other Street");
formData.append("nok_phone", "08087654321");
formData.append("monthly_savings", 5000);
formData.append("share_subscription", 10000);
formData.append("month_commence", "January");
formData.append("password", "MySecret123");
formData.append("password_confirmation", "MySecret123");
formData.append("salary_deduction_agreement", 1);
formData.append("membership_declaration", 1);

// Image from camera/gallery picker
formData.append("member_image", {
    uri: imageUri,
    type: "image/jpeg",
    name: "member_photo.jpg",
});
formData.append("signature_image", {
    uri: signatureUri,
    type: "image/png",
    name: "signature.png",
});

const response = await apiClient.post("/register", formData, {
    headers: { "Content-Type": "multipart/form-data" },
});
```

**Success Response (201):**

```json
{
  "success": true,
  "message": "Registration completed successfully. Please check your email. Your account is pending admin approval.",
  "data": {
    "user": { ... }
  }
}
```

> **Note:** After registration, the user cannot login until an admin approves the account (`admin_sign` changes from `"No"` to `"Yes"`).

---

#### `POST /api/v1/forgot-password`

Send a password reset link to the user's email.

**Request:**

```json
{
    "email": "john@example.com"
}
```

**Success Response (200):**

```json
{
    "success": true,
    "message": "Password reset link sent to your email."
}
```

> The reset link directs users to the web-based reset form. For a fully native experience, a custom deep-link flow can be added in future iterations.

---

### Auth – Protected

All endpoints below require the header:

```
Authorization: Bearer <token>
```

---

#### `GET /api/v1/me`

Get the authenticated user's profile.

**Success Response (200):**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "title": "Mr",
    "surname": "Doe",
    "firstname": "John",
    ...
  }
}
```

---

#### `POST /api/v1/logout`

Revoke the current token (single device logout).

**Success Response (200):**

```json
{
    "success": true,
    "message": "Logged out successfully."
}
```

---

#### `POST /api/v1/logout-all`

Revoke all tokens (logout from all devices).

**Success Response (200):**

```json
{
    "success": true,
    "message": "Logged out from all devices."
}
```

---

#### `POST /api/v1/change-password`

Change the authenticated user's password. All existing tokens are revoked and a new token is returned.

**Request:**

```json
{
    "current_password": "OldPass123",
    "password": "NewPass456",
    "password_confirmation": "NewPass456",
    "device_name": "android"
}
```

**Success Response (200):**

```json
{
    "success": true,
    "message": "Password changed successfully.",
    "data": {
        "token": "2|xyz789..."
    }
}
```

---

### Lookup Data

These endpoints are **public** (no token needed) and provide data for registration dropdowns.

---

#### `GET /api/v1/states`

```json
{
    "success": true,
    "data": [
        { "id": 1, "name": "Ogun" },
        { "id": 2, "name": "Lagos" }
    ]
}
```

#### `GET /api/v1/states/{stateId}/lgas`

```json
{
    "success": true,
    "data": [
        { "id": 1, "name": "Ijebu Ode" },
        { "id": 2, "name": "Abeokuta South" }
    ]
}
```

#### `GET /api/v1/faculties`

```json
{
    "success": true,
    "data": [
        { "id": 1, "name": "Engineering" },
        { "id": 2, "name": "Sciences" }
    ]
}
```

#### `GET /api/v1/faculties/{facultyId}/departments`

```json
{
    "success": true,
    "data": [
        { "id": 1, "name": "Computer Science" },
        { "id": 2, "name": "Electrical Engineering" }
    ]
}
```

---

## Error Handling

### HTTP Status Codes

| Code  | Meaning                                                          |
| ----- | ---------------------------------------------------------------- |
| `200` | Success                                                          |
| `201` | Created (registration)                                           |
| `401` | Unauthenticated – token missing, expired, or invalid             |
| `403` | Forbidden – account pending approval or insufficient permissions |
| `404` | Not Found                                                        |
| `422` | Validation Error – check `errors` object                         |
| `429` | Too Many Requests – rate limited                                 |
| `500` | Server Error                                                     |

### React Native error handling pattern (Redux)

```javascript
import { useDispatch, useSelector } from "react-redux";
import {
    loginUser,
    selectAuthLoading,
    selectAuthError,
    clearError,
} from "../store/slices/authSlice";

// In your component:
const dispatch = useDispatch();
const loading = useSelector(selectAuthLoading);
const error = useSelector(selectAuthError);

// Dispatch the login thunk
const result = await dispatch(loginUser({ email, password }));

if (loginUser.rejected.match(result)) {
    // result.payload contains the error object from rejectWithValue
    const { message, errors } = result.payload;

    if (errors) {
        // Validation errors (422) – show field-specific messages
        const msgs = Object.values(errors).flat().join("\n");
        Alert.alert("Validation Error", msgs);
    } else {
        // Generic error (403 pending approval, 500 server, etc.)
        Alert.alert("Error", message || "Something went wrong.");
    }
}

// Clean up errors when leaving screen
useEffect(() => {
    return () => {
        dispatch(clearError());
    };
}, []);
```

---

## API Endpoints – Member

All member endpoints require authentication (`Bearer token`) and are prefixed with `/api/v1/member`.

> **Base:** `https://your-domain.com/api/v1/member`

---

### Member – Dashboard

#### `GET /member/dashboard`

Returns the member's financial overview for the home screen.

**Response:**

```json
{
    "success": true,
    "data": {
        "savings_balance": 125000.0,
        "total_savings": 150000.0,
        "total_withdrawals": 25000.0,
        "monthly_contribution": 5000.0,
        "share_capital": 10000.0,
        "active_loans_count": 1,
        "total_loan_balance": 45000.0,
        "pending_guarantor_count": 2,
        "unread_notifications": 3,
        "recent_transactions": [
            {
                "id": 45,
                "type": "Monthly Savings",
                "amount": 5000.0,
                "month": "February",
                "year": 2026,
                "status": "completed",
                "created_at": "2026-02-15T10:30:00+01:00"
            }
        ]
    }
}
```

**Mobile screen mapping:** `DashboardScreen.js`

| Card               | Data key                  |
| ------------------ | ------------------------- |
| Total Savings      | `savings_balance`         |
| Monthly Contrib.   | `monthly_contribution`    |
| Share Capital      | `share_capital`           |
| Active Loans       | `active_loans_count`      |
| Outstanding Loan   | `total_loan_balance`      |
| Guarantor Badge    | `pending_guarantor_count` |
| Notification Badge | `unread_notifications`    |
| Recent list        | `recent_transactions[]`   |

---

### Member – Savings

#### `GET /member/savings`

Returns savings breakdown by type, current month total, and paginated savings history.

**Query params (all optional):**

| Param            | Type | Description                 |
| ---------------- | ---- | --------------------------- |
| `saving_type_id` | int  | Filter by saving type       |
| `start_date`     | date | From date (YYYY-MM-DD)      |
| `end_date`       | date | To date (YYYY-MM-DD)        |
| `per_page`       | int  | Items per page (default 20) |

**Response:**

```json
{
    "success": true,
    "data": {
        "type_balances": [
            { "id": 1, "name": "Monthly Savings", "amount": 80000.0 },
            { "id": 2, "name": "Special Savings", "amount": 45000.0 }
        ],
        "current_month_total": 5000.0,
        "total_savings": 125000.0,
        "total_withdrawals": 25000.0,
        "savings_balance": 100000.0,
        "recent_savings": {
            "current_page": 1,
            "data": [
                {
                    "id": 45,
                    "user_id": 1,
                    "saving_type_id": 1,
                    "amount": "5000.00",
                    "status": "completed",
                    "saving_type": { "id": 1, "name": "Monthly Savings" },
                    "created_at": "2026-02-15T10:30:00.000000Z"
                }
            ],
            "last_page": 3,
            "per_page": 20,
            "total": 58
        }
    }
}
```

**Mobile screen:** `SavingsScreen.js`

- Top cards: map over `type_balances[]` + show `savings_balance` & `current_month_total`
- Bottom table: paginated from `recent_savings` — use `onEndReached` for infinite scroll
- Filter bar: saving type dropdown + date pickers → re-fetch with query params

---

#### `GET /member/savings/monthly-summary?year=2026`

Monthly savings grid (rows = saving types, columns = Jan–Dec).

**Query params:**

| Param  | Type | Default      |
| ------ | ---- | ------------ |
| `year` | int  | Current year |

**Response:**

```json
{
    "success": true,
    "data": {
        "year": "2026",
        "years": [
            { "id": 3, "year": 2026 },
            { "id": 2, "year": 2025 }
        ],
        "months": [
            { "id": 1, "name": "January" },
            { "id": 2, "name": "February" }
        ],
        "summary": [
            {
                "saving_type_id": 1,
                "name": "Monthly Savings",
                "months": [
                    { "month_id": 1, "month": "January", "amount": 5000.0 },
                    { "month_id": 2, "month": "February", "amount": 5000.0 }
                ],
                "total": 10000.0
            }
        ]
    }
}
```

**Mobile screen:** `SavingsMonthlySummaryScreen.js` — horizontally scrollable table. Year picker at top.

---

### Member – Savings Settings

Members can set how much they want deducted per saving type per month.

#### `GET /member/savings-settings`

**Response:** paginated list of settings + dropdown data for the create form.

```json
{
    "success": true,
    "data": {
        "settings": {
            "current_page": 1,
            "data": [
                {
                    "id": 12,
                    "saving_type": { "id": 1, "name": "Monthly Savings" },
                    "month": { "id": 3, "name": "March" },
                    "year": { "id": 3, "year": 2026 },
                    "amount": "5000.00",
                    "status": "approved"
                }
            ]
        },
        "saving_types": [{ "id": 1, "name": "Monthly Savings" }],
        "months": [{ "id": 1, "name": "January" }],
        "years": [{ "id": 3, "year": 2026 }]
    }
}
```

#### `POST /member/savings-settings`

| Field            | Type   | Required |
| ---------------- | ------ | -------- |
| `saving_type_id` | int    | Yes      |
| `month_id`       | int    | Yes      |
| `year_id`        | int    | Yes      |
| `amount`         | number | Yes      |

**Response (201):**

```json
{
    "success": true,
    "message": "Monthly savings setting created successfully.",
    "data": {
        "id": 13,
        "amount": "5000.00",
        "status": "approved",
        "...": "..."
    }
}
```

#### `PUT /member/savings-settings/{id}`

Only pending settings can be updated. Body: `{ "amount": 7000 }`

#### `DELETE /member/savings-settings/{id}`

Only pending settings can be deleted.

---

### Member – Shares

#### `GET /member/shares`

```json
{
    "success": true,
    "data": {
        "shares": [
            {
                "id": 5,
                "share_type": "Ordinary Share",
                "certificate_number": "SHR-2026-AbCdEfGh",
                "amount_paid": 10000.0,
                "status": "approved",
                "created_at": "2026-01-10T09:00:00+01:00"
            }
        ],
        "total_approved": 10000.0,
        "share_types": [
            {
                "id": 1,
                "name": "Ordinary Share",
                "minimum_amount": "1000.00",
                "maximum_amount": "50000.00"
            }
        ]
    }
}
```

**Mobile screen:** `SharesScreen.js` — Top card shows `total_approved`, list shows `shares[]`, FAB button → purchase form using `share_types[]`.

#### `POST /member/shares`

| Field             | Type | Required |
| ----------------- | ---- | -------- |
| `share_type_id`   | int  | Yes      |
| `number_of_units` | int  | Yes (≥1) |

**Response (201):**

```json
{
    "success": true,
    "message": "Share purchase request submitted successfully.",
    "data": {
        "id": 6,
        "share_type_id": 1,
        "certificate_number": "SHR-2026-XyZaBcDe",
        "amount_paid": 5000.0,
        "status": "pending"
    }
}
```

---

### Member – Loans

#### `GET /member/loans`

Returns all member's loans with repayment progress and available loan types.

```json
{
    "success": true,
    "data": {
        "loans": [
            {
                "id": 3,
                "reference": "LOAN-65F2A1B3C",
                "loan_type": "Soft Loan",
                "amount": 100000.0,
                "interest_amount": 10000.0,
                "total_amount": 110000.0,
                "monthly_payment": 18333.33,
                "paid_amount": 36666.66,
                "balance": 73333.34,
                "duration": 6,
                "purpose": "School Fees",
                "status": "approved",
                "start_date": "2026-01-15",
                "end_date": "2026-07-15",
                "application_fee": 500.0,
                "guarantors": [
                    { "name": "John Doe", "status": "approved" },
                    { "name": "Jane Smith", "status": "pending" }
                ],
                "created_at": "2026-01-15T10:00:00+01:00"
            }
        ],
        "loan_types": [
            {
                "id": 1,
                "name": "Soft Loan",
                "interest_rate": "10.00",
                "duration_months": 12,
                "minimum_amount": "10000.00",
                "maximum_amount": "500000.00",
                "no_guarantors": 2,
                "application_fee": "500.00",
                "required_active_savings_months": 6,
                "savings_multiplier": "3.00"
            }
        ]
    }
}
```

**Mobile screen:** `LoansScreen.js`

- Active loans section: filter `loans[]` where `status === 'approved'` — show progress bar `paid_amount / total_amount`
- Loan history: all items
- Apply button → `LoanApplyScreen.js` with `loan_types[]` for the form

#### `GET /member/loans/{id}`

Detailed loan view with repayment history.

```json
{
    "success": true,
    "data": {
        "id": 3,
        "reference": "LOAN-65F2A1B3C",
        "loan_type": "Soft Loan",
        "amount": 100000.0,
        "interest_amount": 10000.0,
        "total_amount": 110000.0,
        "monthly_payment": 18333.33,
        "paid_amount": 36666.66,
        "balance": 73333.34,
        "duration": 6,
        "purpose": "School Fees",
        "status": "approved",
        "start_date": "2026-01-15",
        "end_date": "2026-07-15",
        "application_fee": 500.0,
        "guarantors": [
            {
                "id": 1,
                "name": "John Doe",
                "member_no": "MEM-001",
                "status": "approved",
                "comment": null
            },
            {
                "id": 2,
                "name": "Jane Smith",
                "member_no": "MEM-002",
                "status": "pending",
                "comment": null
            }
        ],
        "repayments": [
            {
                "id": 10,
                "reference": "REP-ABC123",
                "amount": 18333.33,
                "payment_date": "2026-02-15",
                "status": "completed"
            },
            {
                "id": 11,
                "reference": "REP-DEF456",
                "amount": 18333.33,
                "payment_date": "2026-03-15",
                "status": "completed"
            }
        ],
        "created_at": "2026-01-15T10:00:00+01:00"
    }
}
```

**Mobile screen:** `LoanDetailScreen.js` — summary card + guarantor list + repayment FlatList.

#### `POST /member/loans`

Apply for a new loan.

| Field           | Type   | Required              |
| --------------- | ------ | --------------------- |
| `loan_type_id`  | int    | Yes                   |
| `amount`        | number | Yes (≥1000)           |
| `duration`      | int    | Yes (≤ loan type max) |
| `purpose`       | string | Yes (max 500)         |
| `guarantor_ids` | int[]  | If loan type requires |

**Response (201):**

```json
{
    "success": true,
    "message": "Loan application submitted successfully.",
    "data": {
        "id": 4,
        "reference": "LOAN-67A3B2C1D",
        "status": "pending",
        "...": "..."
    }
}
```

#### `GET /member/loan-types`

Returns all active loan types (useful for the loan application form).

#### `POST /member/loan-calculator`

Check eligibility and calculate repayment before applying.

| Field          | Type   | Required   |
| -------------- | ------ | ---------- |
| `loan_type_id` | int    | Yes        |
| `amount`       | number | Yes        |
| `duration`     | int    | Yes (1–18) |

**Response:**

```json
{
    "success": true,
    "data": {
        "eligibility": {
            "eligible": true,
            "messages": []
        },
        "loan_details": {
            "principal": 100000.0,
            "interest_rate": 10.0,
            "total_interest": 10000.0,
            "total_amount": 110000.0,
            "monthly_repayment": 18333.33,
            "duration": 6
        },
        "loan_type": { "id": 1, "name": "Soft Loan" }
    }
}
```

**Mobile screen:** `LoanCalculatorScreen.js` — form → results card showing eligibility + breakdown.

#### `GET /member/members?search=john`

Search other members to select as guarantors. Returns `id`, `surname`, `firstname`, `member_no`.

---

### Member – Guarantor Requests

When another member applies for a loan and selects you as guarantor.

#### `GET /member/guarantor-requests`

```json
{
    "success": true,
    "data": [
        {
            "id": 7,
            "loan_id": 4,
            "borrower": "Adewale Ogunleye",
            "member_no": "MEM-042",
            "loan_type": "Soft Loan",
            "loan_amount": 100000.0,
            "status": "pending",
            "comment": null,
            "created_at": "2026-02-20T14:00:00+01:00"
        }
    ]
}
```

**Mobile screen:** `GuarantorRequestsScreen.js` — list with Accept/Reject buttons for pending items.

#### `POST /member/guarantor-requests/{loanId}/respond`

| Field      | Type   | Required                       |
| ---------- | ------ | ------------------------------ |
| `response` | string | Yes (`approved` or `rejected`) |
| `reason`   | string | Required if `rejected`         |

**Response:**

```json
{ "success": true, "message": "Response submitted successfully." }
```

---

### Member – Withdrawals

#### `GET /member/withdrawals`

**Query params:** `status` (pending/approved/completed/rejected), `per_page`.

```json
{
    "success": true,
    "data": {
        "withdrawals": {
            "current_page": 1,
            "data": [
                {
                    "id": 8,
                    "saving_type": { "id": 1, "name": "Monthly Savings" },
                    "amount": "25000.00",
                    "bank_name": "GTBank",
                    "account_number": "0123456789",
                    "account_name": "John Doe",
                    "reason": "Emergency",
                    "reference": "WDR-ABCDEFGH",
                    "status": "pending",
                    "created_at": "2026-02-18T09:00:00.000000Z"
                }
            ]
        },
        "total_amount": 25000.0,
        "approved_amount": 0.0
    }
}
```

**Mobile screen:** `WithdrawalsScreen.js` — list with status badges, summary cards at top, FAB → create form.

#### `POST /member/withdrawals`

| Field            | Type   | Required |
| ---------------- | ------ | -------- |
| `saving_type_id` | int    | Yes      |
| `amount`         | number | Yes (≥1) |
| `reason`         | string | Yes      |
| `bank_name`      | string | Yes      |
| `account_number` | string | Yes      |
| `account_name`   | string | Yes      |

**Response (201):**

```json
{
    "success": true,
    "message": "Withdrawal request submitted successfully.",
    "data": {
        "id": 9,
        "reference": "WDR-XYZABC12",
        "status": "pending",
        "...": "..."
    }
}
```

**Validation:** Returns 422 if amount exceeds available balance for that saving type.

#### `GET /member/withdrawals/{id}`

Detailed withdrawal view.

---

### Member – Transactions / Passbook

#### `GET /member/transactions`

The member's full passbook.

**Query params:** `type` (savings/withdrawal/loan/loan_repayment/commodity_payment), `start_date`, `end_date`, `per_page` (default 50).

```json
{
    "success": true,
    "data": {
        "transactions": {
            "current_page": 1,
            "data": [
                {
                    "id": 100,
                    "type": "savings",
                    "credit_amount": "5000.00",
                    "debit_amount": "0.00",
                    "balance": "125000.00",
                    "reference": "TRX-2026-AbCdEfGh",
                    "description": "Monthly Savings Contribution",
                    "transaction_date": "2026-02-15T10:30:00.000000Z",
                    "status": "completed"
                }
            ]
        },
        "total_credits": 200000.0,
        "total_debits": 75000.0,
        "net_balance": 125000.0
    }
}
```

**Mobile screen:** `PassbookScreen.js`

- Summary cards: `total_credits`, `total_debits`, `net_balance`
- FlatList with credit/debit color coding (green/red)
- Filter bar: type dropdown + date range pickers
- Tap row → `TransactionDetailScreen.js`

#### `GET /member/transactions/{id}`

Single transaction detail.

---

### Member – Commodities

#### `GET /member/commodities`

Browse available commodities (paginated).

```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "name": "50kg Rice",
                "description": "Premium rice bag",
                "price": "35000.00",
                "quantity_available": 50,
                "image": "commodities/rice.jpg",
                "allow_installment": true,
                "max_installment_months": 6,
                "initial_deposit_percentage": "30.00",
                "start_date": "2026-01-01",
                "end_date": "2026-06-30"
            }
        ]
    }
}
```

**Mobile screen:** `CommoditiesScreen.js` — grid/list of commodities with images.

#### `GET /member/commodities/{id}`

Full commodity detail (includes image URL, installment info).

#### `POST /member/commodities/{id}/subscribe`

| Field             | Type   | Required                |
| ----------------- | ------ | ----------------------- |
| `quantity`        | int    | Yes (≥1, ≤ available)   |
| `reason`          | string | No                      |
| `payment_type`    | string | `full` or `installment` |
| `initial_deposit` | number | Required if installment |

**Response (201):**

```json
{
    "success": true,
    "message": "Subscription submitted successfully.",
    "data": {
        "id": 5,
        "reference": "COM-A1B2C3D4-20260220",
        "status": "pending",
        "total_amount": 35000.0
    }
}
```

#### `GET /member/commodity-subscriptions`

My subscriptions (paginated). Each includes commodity name, status, total amount, payment type.

#### `GET /member/commodity-subscriptions/{id}`

Detailed subscription with payment progress.

```json
{
    "success": true,
    "data": {
        "subscription": {
            "id": 5,
            "commodity": { "name": "50kg Rice" },
            "total_amount": 35000.0,
            "payment_type": "installment",
            "...": "..."
        },
        "total_amount": 35000.0,
        "initial_deposit": 10500.0,
        "paid_amount": 17500.0,
        "remaining": 17500.0
    }
}
```

#### `POST /member/commodity-subscriptions/{id}/payments`

Make installment payment.

| Field               | Type   | Required                          |
| ------------------- | ------ | --------------------------------- |
| `amount`            | number | Yes (≤ remaining)                 |
| `payment_method`    | string | Yes: cash/bank_transfer/deduction |
| `payment_reference` | string | No                                |
| `month_id`          | int    | Yes                               |
| `year_id`           | int    | Yes                               |

---

### Member – Notifications

#### `GET /member/notifications`

Paginated notifications list.

```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 20,
                "title": "Loan Approved",
                "message": "Your soft loan of ₦100,000 has been approved.",
                "type": "loan",
                "read_at": null,
                "data": { "loan_id": 3 },
                "created_at": "2026-02-20T14:30:00.000000Z"
            }
        ]
    }
}
```

**Mobile screen:** `NotificationsScreen.js` — unread items bolded, tap marks as read + navigates to related screen.

#### `POST /member/notifications/{id}/read`

Marks a single notification as read.

#### `POST /member/notifications/read-all`

Marks all unread notifications as read.

---

### Member – Financial Summary

#### `GET /member/financial-summary?year=2026`

Comprehensive yearly financial breakdown (savings, shares, loan repayments, commodity payments) by month.

**Response:**

```json
{
    "success": true,
    "data": {
        "year": "2026",
        "years": [{ "id": 3, "year": 2026 }],
        "months": [
            { "id": 1, "name": "January" },
            { "id": 2, "name": "February" }
        ],
        "savings": [
            {
                "id": 1,
                "name": "Monthly Savings",
                "months": [
                    { "month_id": 1, "amount": 5000.0 },
                    { "month_id": 2, "amount": 5000.0 }
                ],
                "total": 10000.0
            }
        ],
        "shares": {
            "months": [{ "month_id": 1, "amount": 1000.0 }],
            "total": 1000.0
        },
        "loans": [
            {
                "id": 3,
                "name": "Soft Loan (LOAN-65F2A1B3C)",
                "months": [{ "month_id": 2, "amount": 18333.33 }],
                "total": 18333.33
            }
        ],
        "commodities": []
    }
}
```

**Mobile screen:** `FinancialSummaryScreen.js` — year picker + horizontally scrollable table with savings, shares, loans, commodities rows.

---

### Member – Profile

#### `PUT /member/profile`

Update editable profile fields.

| Field              | Type   | Required |
| ------------------ | ------ | -------- |
| `phone_number`     | string | No       |
| `home_address`     | string | No       |
| `nok`              | string | No       |
| `nok_phone`        | string | No       |
| `nok_address`      | string | No       |
| `nok_relationship` | string | No       |
| `bank_name`        | string | No       |
| `account_number`   | string | No       |
| `account_name`     | string | No       |

**Response:** Updated user object.

---

### Member – Resources

#### `GET /member/resources`

Downloadable resources/documents (paginated).

```json
{
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "title": "Membership Handbook",
                "description": "Official cooperative handbook",
                "file_type": "pdf",
                "file_size": 2048,
                "download_url": "https://your-domain.com/api/v1/member/resources/1/download",
                "created_at": "2026-01-01T00:00:00+01:00"
            }
        ]
    }
}
```

#### `GET /member/resources/{id}/download`

Returns the file as a streamed download. Use `Linking.openURL()` or `expo-file-system` in the mobile app.

---

## Available Modules – Admin (Upcoming)

### Admin Endpoints (`/api/v1/admin/...`)

| Module                 | Planned Endpoints                      | Description                              |
| ---------------------- | -------------------------------------- | ---------------------------------------- |
| **Dashboard**          | `GET /admin/dashboard`                 | System-wide stats                        |
| **Members**            | `GET /admin/members`                   | List all members (paginated, searchable) |
|                        | `GET /admin/members/{id}`              | Member details                           |
|                        | `PUT /admin/members/{id}`              | Update member                            |
|                        | `POST /admin/members/{id}/approve`     | Approve membership                       |
| **Entrance Fees**      | `GET /admin/entrance-fees`             | List entrance fee records                |
|                        | `POST /admin/entrance-fees`            | Record entrance fee                      |
| **Savings Management** | `GET /admin/savings`                   | All savings records                      |
|                        | `POST /admin/savings`                  | Record a saving                          |
| **Saving Types**       | `CRUD /admin/saving-types`             | Manage saving categories                 |
| **Shares**             | `CRUD /admin/shares`                   | Manage share transactions                |
| **Share Types**        | `CRUD /admin/share-types`              | Manage share categories                  |
| **Loans**              | `GET /admin/loans`                     | All loan applications                    |
|                        | `POST /admin/loans/{id}/approve`       | Approve loan                             |
|                        | `POST /admin/loans/{id}/reject`        | Reject loan                              |
| **Loan Types**         | `CRUD /admin/loan-types`               | Manage loan categories                   |
| **Loan Repayments**    | `POST /admin/loan-repayments`          | Record repayment                         |
| **Transactions**       | `GET /admin/transactions`              | All transactions                         |
| **Withdrawals**        | `POST /admin/withdrawals/{id}/approve` | Approve withdrawal                       |
| **Commodities**        | `CRUD /admin/commodities`              | Manage commodities                       |
| **Reports**            | `GET /admin/reports`                   | Generate reports                         |
| **Financial Summary**  | `GET /admin/financial-summary`         | Overall cooperative finances             |
| **FAQs**               | `CRUD /admin/faqs`                     | Manage FAQs                              |
| **Admin Users**        | `CRUD /admin/admins`                   | Manage admin accounts                    |
| **Roles**              | `CRUD /admin/roles`                    | Manage roles & permissions               |

---

## React Native Integration Guide (Redux)

### Recommended Project Structure (Expo + Redux)

```
OgitechCoop/
├── App.js                        # Entry point: Provider + PersistGate + Navigation
├── app.json                      # Expo config
├── package.json
└── src/
    ├── api/
    │   └── client.js              # Axios instance (reads token from Redux store)
    ├── store/
    │   ├── index.js               # configureStore + persistor
    │   └── slices/
    │       ├── authSlice.js       # Auth state: user, token, role + async thunks
    │       ├── lookupSlice.js     # States, LGAs, faculties, departments
    │       ├── dashboardSlice.js  # Dashboard summary data
    │       ├── savingsSlice.js    # Savings list, monthly summary, settings
    │       ├── sharesSlice.js     # Shares list + purchase
    │       ├── loansSlice.js      # Loans list, detail, apply, calculator
    │       ├── withdrawalsSlice.js # Withdrawal list + request
    │       ├── transactionsSlice.js # Passbook transactions
    │       ├── commoditiesSlice.js # Commodities, subscriptions, payments
    │       ├── notificationsSlice.js # Notifications + read
    │       └── financialSlice.js  # Financial summary by year
    ├── navigation/
    │   ├── AppNavigator.js        # Reads Redux state to pick stack
    │   ├── AuthStack.js           # Login, Register, Forgot Password
    │   ├── MemberTabs.js          # Bottom tab navigator for members
    │   └── AdminTabs.js           # Bottom tab navigator for admins
    ├── screens/
    │   ├── auth/
    │   │   ├── LoginScreen.js
    │   │   ├── RegisterScreen.js  # Multi-step form using lookupSlice
    │   │   └── ForgotPasswordScreen.js
    │   ├── member/
    │   │   ├── DashboardScreen.js
    │   │   ├── SavingsScreen.js
    │   │   ├── SavingsMonthlySummaryScreen.js
    │   │   ├── SavingsSettingsScreen.js
    │   │   ├── SharesScreen.js
    │   │   ├── LoansScreen.js
    │   │   ├── LoanDetailScreen.js
    │   │   ├── LoanApplyScreen.js
    │   │   ├── LoanCalculatorScreen.js
    │   │   ├── GuarantorRequestsScreen.js
    │   │   ├── WithdrawalsScreen.js
    │   │   ├── PassbookScreen.js
    │   │   ├── TransactionDetailScreen.js
    │   │   ├── CommoditiesScreen.js
    │   │   ├── CommodityDetailScreen.js
    │   │   ├── CommoditySubscriptionsScreen.js
    │   │   ├── NotificationsScreen.js
    │   │   ├── FinancialSummaryScreen.js
    │   │   ├── ProfileScreen.js
    │   │   └── ResourcesScreen.js
    │   └── admin/
    │       ├── DashboardScreen.js
    │       ├── MembersScreen.js
    │       ├── SavingsScreen.js
    │       ├── LoansScreen.js
    │       └── ReportsScreen.js
    ├── components/
    │   ├── FormInput.js
    │   ├── Button.js
    │   ├── Card.js
    │   ├── EmptyState.js
    │   ├── FilterBar.js
    │   └── LoadingSpinner.js
    └── utils/
        ├── theme.js               # Colors, fonts
        ├── formatters.js          # Currency, date formatting
        └── validators.js          # Form validation helpers
```

### Navigation Setup (with Redux Provider + PersistGate)

```javascript
// App.js
import React from "react";
import { Provider } from "react-redux";
import { PersistGate } from "redux-persist/integration/react";
import { NavigationContainer } from "@react-navigation/native";
import { useSelector } from "react-redux";
import {
    selectIsAuthenticated,
    selectRole,
} from "./src/store/slices/authSlice";
import { store, persistor } from "./src/store";
import AuthStack from "./src/navigation/AuthStack";
import MemberTabs from "./src/navigation/MemberTabs";
import AdminTabs from "./src/navigation/AdminTabs";
import { ActivityIndicator, View } from "react-native";

function RootNavigator() {
    const isAuthenticated = useSelector(selectIsAuthenticated);
    const role = useSelector(selectRole);

    if (!isAuthenticated) return <AuthStack />;
    if (role === "admin") return <AdminTabs />;
    return <MemberTabs />;
}

function LoadingScreen() {
    return (
        <View
            style={{
                flex: 1,
                justifyContent: "center",
                alignItems: "center",
                backgroundColor: "#faf5ff",
            }}
        >
            <ActivityIndicator size="large" color="#7e22ce" />
        </View>
    );
}

export default function App() {
    return (
        <Provider store={store}>
            <PersistGate loading={<LoadingScreen />} persistor={persistor}>
                <NavigationContainer>
                    <RootNavigator />
                </NavigationContainer>
            </PersistGate>
        </Provider>
    );
}
```

> **Interview note:** `PersistGate` delays rendering until the Redux store is rehydrated from AsyncStorage. This means if a user was logged in, their token and profile are restored automatically – no manual `checkAuth()` needed.

### Login Screen Example (Redux)

```javascript
// src/screens/auth/LoginScreen.js
import React, { useState, useEffect } from "react";
import {
    View,
    Text,
    TextInput,
    TouchableOpacity,
    Alert,
    ActivityIndicator,
    StyleSheet,
    KeyboardAvoidingView,
    Platform,
} from "react-native";
import { useDispatch, useSelector } from "react-redux";
import {
    loginUser,
    selectAuthLoading,
    selectAuthError,
    clearError,
} from "../../store/slices/authSlice";

export default function LoginScreen({ navigation }) {
    const dispatch = useDispatch();
    const loading = useSelector(selectAuthLoading);
    const error = useSelector(selectAuthError);

    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");

    // Clear Redux errors when leaving the screen
    useEffect(() => {
        return () => {
            dispatch(clearError());
        };
    }, [dispatch]);

    async function handleLogin() {
        if (!email || !password) {
            Alert.alert("Error", "Please enter email and password.");
            return;
        }

        const result = await dispatch(loginUser({ email, password }));

        if (loginUser.rejected.match(result)) {
            const msg =
                result.payload?.message ||
                result.payload?.errors?.email?.[0] ||
                "Login failed. Please try again.";
            Alert.alert("Login Failed", msg);
        }
        // On success, navigation happens automatically via RootNavigator
        // because selectIsAuthenticated changes from false → true
    }

    return (
        <KeyboardAvoidingView
            behavior={Platform.OS === "ios" ? "padding" : "height"}
            style={styles.container}
        >
            <View style={styles.card}>
                <Text style={styles.title}>Welcome Back!</Text>
                <Text style={styles.subtitle}>
                    Sign in to access your account
                </Text>

                <TextInput
                    style={styles.input}
                    placeholder="Email or Member Number"
                    value={email}
                    onChangeText={setEmail}
                    keyboardType="email-address"
                    autoCapitalize="none"
                />

                <TextInput
                    style={styles.input}
                    placeholder="Password"
                    value={password}
                    onChangeText={setPassword}
                    secureTextEntry
                />

                <TouchableOpacity
                    style={[styles.button, loading && styles.buttonDisabled]}
                    onPress={handleLogin}
                    disabled={loading}
                >
                    {loading ? (
                        <ActivityIndicator color="#fff" />
                    ) : (
                        <Text style={styles.buttonText}>Sign In</Text>
                    )}
                </TouchableOpacity>

                <TouchableOpacity
                    onPress={() => navigation.navigate("ForgotPassword")}
                >
                    <Text style={styles.link}>Forgot password?</Text>
                </TouchableOpacity>
            </View>
        </KeyboardAvoidingView>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        justifyContent: "center",
        backgroundColor: "#faf5ff",
        padding: 20,
    },
    card: {
        backgroundColor: "#fff",
        borderRadius: 12,
        padding: 24,
        elevation: 4,
    },
    title: {
        fontSize: 28,
        fontWeight: "bold",
        color: "#6b21a8",
        textAlign: "center",
    },
    subtitle: {
        fontSize: 14,
        color: "#6b7280",
        textAlign: "center",
        marginBottom: 24,
    },
    input: {
        borderWidth: 1,
        borderColor: "#d1d5db",
        borderRadius: 8,
        padding: 14,
        fontSize: 16,
        marginBottom: 16,
    },
    button: {
        backgroundColor: "#7e22ce",
        borderRadius: 8,
        padding: 16,
        alignItems: "center",
        marginBottom: 12,
    },
    buttonDisabled: { opacity: 0.7 },
    buttonText: { color: "#fff", fontSize: 16, fontWeight: "600" },
    link: { color: "#7e22ce", textAlign: "center", marginTop: 8 },
});
```

> **Interview talking points:**
>
> - `useDispatch` to dispatch the `loginUser` async thunk
> - `useSelector` to read loading/error state reactively
> - `createAsyncThunk` handles pending/fulfilled/rejected automatically
> - No try/catch needed for the thunk itself – check `loginUser.rejected.match(result)` instead
> - Navigation is automatic because `RootNavigator` re-renders when `selectIsAuthenticated` changes

### Multi-Step Registration Screen Example (Redux)

```javascript
// src/screens/auth/RegisterScreen.js
import React, { useState, useEffect } from "react";
import {
    View,
    Text,
    TextInput,
    TouchableOpacity,
    ScrollView,
    Alert,
    StyleSheet,
    ActivityIndicator,
} from "react-native";
import { Picker } from "@react-native-picker/picker";
import * as ImagePicker from "expo-image-picker";
import { useDispatch, useSelector } from "react-redux";
import {
    registerUser,
    selectAuthLoading,
    clearError,
} from "../../store/slices/authSlice";
import {
    fetchStates,
    fetchLgas,
    fetchFaculties,
    fetchDepartments,
    clearLgas,
    clearDepartments,
} from "../../store/slices/lookupSlice";

const STEPS = [
    "Personal",
    "Contact",
    "Employment",
    "Next of Kin",
    "Financial",
    "Documents",
];

export default function RegisterScreen({ navigation }) {
    const dispatch = useDispatch();
    const loading = useSelector(selectAuthLoading);
    const { states, lgas, faculties, departments } = useSelector(
        (state) => state.lookup,
    );

    const [step, setStep] = useState(0);
    const [formData, setFormData] = useState({});

    // Load lookup data on mount
    useEffect(() => {
        dispatch(fetchStates());
        dispatch(fetchFaculties());
        return () => {
            dispatch(clearError());
        };
    }, [dispatch]);

    function handleStateChange(stateId) {
        updateField("state_id", stateId);
        dispatch(clearLgas());
        dispatch(fetchLgas(stateId));
    }

    function handleFacultyChange(facultyId) {
        updateField("faculty_id", facultyId);
        dispatch(clearDepartments());
        dispatch(fetchDepartments(facultyId));
    }

    function updateField(key, value) {
        setFormData((prev) => ({ ...prev, [key]: value }));
    }

    function nextStep() {
        if (step < STEPS.length - 1) setStep(step + 1);
    }

    function prevStep() {
        if (step > 0) setStep(step - 1);
    }

    async function submitRegistration() {
        const fd = new FormData();
        Object.entries(formData).forEach(([key, value]) => {
            if (value?.uri) {
                fd.append(key, {
                    uri: value.uri,
                    type: value.type || "image/jpeg",
                    name: value.name || `${key}.jpg`,
                });
            } else {
                fd.append(key, value);
            }
        });

        const result = await dispatch(registerUser(fd));

        if (registerUser.fulfilled.match(result)) {
            Alert.alert("Success", result.payload.message, [
                { text: "OK", onPress: () => navigation.navigate("Login") },
            ]);
        } else {
            const errors = result.payload?.errors;
            if (errors) {
                const msgs = Object.values(errors).flat().join("\n");
                Alert.alert("Validation Error", msgs);
            } else {
                Alert.alert(
                    "Error",
                    result.payload?.message || "Registration failed.",
                );
            }
        }
    }

    // Pick image using expo-image-picker
    async function pickImage(fieldName) {
        const result = await ImagePicker.launchImageLibraryAsync({
            mediaTypes: ImagePicker.MediaTypeOptions.Images,
            quality: 0.8,
        });
        if (!result.canceled) {
            updateField(fieldName, {
                uri: result.assets[0].uri,
                type: "image/jpeg",
                name: `${fieldName}.jpg`,
            });
        }
    }

    // ... render per-step form fields (see field table above)
    // Use states, lgas, faculties, departments from Redux
    // Use handleStateChange / handleFacultyChange for cascading dropdowns
}
```

> **Redux concepts demonstrated:**
>
> - Multiple slices (`authSlice` + `lookupSlice`) working together
> - `createAsyncThunk` for API calls with automatic pending/fulfilled/rejected
> - `useSelector` to read lookup data reactively
> - Pattern matching on `registerUser.fulfilled.match(result)` for flow control
> - Cascading dispatches: selecting a state triggers `fetchLgas`, selecting a faculty triggers `fetchDepartments`

### Theme Colors (matching the web app)

```javascript
// src/utils/theme.js
export const COLORS = {
    primary: "#7e22ce", // Purple-700
    primaryDark: "#6b21a8", // Purple-800
    primaryLight: "#f3e8ff", // Purple-50
    background: "#faf5ff", // Purple-50
    white: "#ffffff",
    gray100: "#f3f4f6",
    gray500: "#6b7280",
    gray700: "#374151",
    danger: "#ef4444",
    success: "#10b981",
    warning: "#f59e0b",
};
```

### Member Redux Slices (Phase 2)

Below are the Redux slices for all member modules. Register each in `src/store/index.js` by importing and adding to `combineReducers`.

#### `src/store/slices/dashboardSlice.js`

```javascript
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import apiClient from "../../api/client";

export const fetchDashboard = createAsyncThunk(
    "dashboard/fetch",
    async (_, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/dashboard");
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

const dashboardSlice = createSlice({
    name: "dashboard",
    initialState: {
        data: null,
        loading: false,
        error: null,
    },
    reducers: {
        clearDashboard: (state) => {
            state.data = null;
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchDashboard.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(fetchDashboard.fulfilled, (state, action) => {
                state.loading = false;
                state.data = action.payload;
            })
            .addCase(fetchDashboard.rejected, (state, action) => {
                state.loading = false;
                state.error =
                    action.payload?.message || "Failed to load dashboard";
            });
    },
});

export const { clearDashboard } = dashboardSlice.actions;
export const selectDashboard = (state) => state.dashboard.data;
export const selectDashboardLoading = (state) => state.dashboard.loading;
export default dashboardSlice.reducer;
```

#### `src/store/slices/savingsSlice.js`

```javascript
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import apiClient from "../../api/client";

export const fetchSavings = createAsyncThunk(
    "savings/fetch",
    async (params = {}, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/savings", { params });
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const fetchSavingsMonthlySummary = createAsyncThunk(
    "savings/fetchMonthlySummary",
    async (year, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/savings/monthly-summary", {
                params: { year },
            });
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const fetchSavingsSettings = createAsyncThunk(
    "savings/fetchSettings",
    async (_, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/savings-settings");
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const createSavingsSetting = createAsyncThunk(
    "savings/createSetting",
    async (data, { rejectWithValue }) => {
        try {
            const res = await apiClient.post("/member/savings-settings", data);
            return res.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

const savingsSlice = createSlice({
    name: "savings",
    initialState: {
        data: null, // { type_balances, current_month_total, savings_balance, recent_savings }
        monthlySummary: null,
        settings: null,
        loading: false,
        error: null,
    },
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(fetchSavings.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchSavings.fulfilled, (state, action) => {
                state.loading = false;
                state.data = action.payload;
            })
            .addCase(fetchSavings.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload?.message;
            })
            .addCase(fetchSavingsMonthlySummary.fulfilled, (state, action) => {
                state.monthlySummary = action.payload;
            })
            .addCase(fetchSavingsSettings.fulfilled, (state, action) => {
                state.settings = action.payload;
            });
    },
});

export const selectSavingsData = (state) => state.savings.data;
export const selectSavingsLoading = (state) => state.savings.loading;
export const selectSavingsMonthlySummary = (state) =>
    state.savings.monthlySummary;
export const selectSavingsSettings = (state) => state.savings.settings;
export default savingsSlice.reducer;
```

#### `src/store/slices/sharesSlice.js`

```javascript
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import apiClient from "../../api/client";

export const fetchShares = createAsyncThunk(
    "shares/fetch",
    async (_, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/shares");
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const purchaseShare = createAsyncThunk(
    "shares/purchase",
    async (data, { rejectWithValue }) => {
        try {
            const res = await apiClient.post("/member/shares", data);
            return res.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

const sharesSlice = createSlice({
    name: "shares",
    initialState: { data: null, loading: false, error: null },
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(fetchShares.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchShares.fulfilled, (state, action) => {
                state.loading = false;
                state.data = action.payload; // { shares, total_approved, share_types }
            })
            .addCase(fetchShares.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload?.message;
            });
    },
});

export const selectSharesData = (state) => state.shares.data;
export const selectSharesLoading = (state) => state.shares.loading;
export default sharesSlice.reducer;
```

#### `src/store/slices/loansSlice.js`

```javascript
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import apiClient from "../../api/client";

export const fetchLoans = createAsyncThunk(
    "loans/fetch",
    async (_, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/loans");
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const fetchLoanDetail = createAsyncThunk(
    "loans/fetchDetail",
    async (loanId, { rejectWithValue }) => {
        try {
            const res = await apiClient.get(`/member/loans/${loanId}`);
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const applyForLoan = createAsyncThunk(
    "loans/apply",
    async (data, { rejectWithValue }) => {
        try {
            const res = await apiClient.post("/member/loans", data);
            return res.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const calculateLoan = createAsyncThunk(
    "loans/calculate",
    async (data, { rejectWithValue }) => {
        try {
            const res = await apiClient.post("/member/loan-calculator", data);
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const searchMembers = createAsyncThunk(
    "loans/searchMembers",
    async (search, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/members", {
                params: { search },
            });
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const fetchGuarantorRequests = createAsyncThunk(
    "loans/guarantorRequests",
    async (_, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/guarantor-requests");
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const respondGuarantor = createAsyncThunk(
    "loans/respondGuarantor",
    async ({ loanId, ...data }, { rejectWithValue }) => {
        try {
            const res = await apiClient.post(
                `/member/guarantor-requests/${loanId}/respond`,
                data,
            );
            return res.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

const loansSlice = createSlice({
    name: "loans",
    initialState: {
        data: null, // { loans, loan_types }
        loanDetail: null,
        calculatorResult: null,
        guarantorRequests: [],
        memberSearchResults: [],
        loading: false,
        error: null,
    },
    reducers: {
        clearLoanDetail: (state) => {
            state.loanDetail = null;
        },
        clearCalculator: (state) => {
            state.calculatorResult = null;
        },
        clearMemberSearch: (state) => {
            state.memberSearchResults = [];
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchLoans.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchLoans.fulfilled, (state, action) => {
                state.loading = false;
                state.data = action.payload;
            })
            .addCase(fetchLoans.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload?.message;
            })
            .addCase(fetchLoanDetail.fulfilled, (state, action) => {
                state.loanDetail = action.payload;
            })
            .addCase(calculateLoan.fulfilled, (state, action) => {
                state.calculatorResult = action.payload;
            })
            .addCase(searchMembers.fulfilled, (state, action) => {
                state.memberSearchResults = action.payload;
            })
            .addCase(fetchGuarantorRequests.fulfilled, (state, action) => {
                state.guarantorRequests = action.payload;
            });
    },
});

export const { clearLoanDetail, clearCalculator, clearMemberSearch } =
    loansSlice.actions;
export const selectLoansData = (state) => state.loans.data;
export const selectLoanDetail = (state) => state.loans.loanDetail;
export const selectCalculatorResult = (state) => state.loans.calculatorResult;
export const selectGuarantorRequests = (state) => state.loans.guarantorRequests;
export const selectMemberSearch = (state) => state.loans.memberSearchResults;
export const selectLoansLoading = (state) => state.loans.loading;
export default loansSlice.reducer;
```

#### `src/store/slices/withdrawalsSlice.js`

```javascript
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import apiClient from "../../api/client";

export const fetchWithdrawals = createAsyncThunk(
    "withdrawals/fetch",
    async (params = {}, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/withdrawals", { params });
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const requestWithdrawal = createAsyncThunk(
    "withdrawals/request",
    async (data, { rejectWithValue }) => {
        try {
            const res = await apiClient.post("/member/withdrawals", data);
            return res.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

const withdrawalsSlice = createSlice({
    name: "withdrawals",
    initialState: { data: null, loading: false, error: null },
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(fetchWithdrawals.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchWithdrawals.fulfilled, (state, action) => {
                state.loading = false;
                state.data = action.payload; // { withdrawals, total_amount, approved_amount }
            })
            .addCase(fetchWithdrawals.rejected, (state, action) => {
                state.loading = false;
                state.error = action.payload?.message;
            });
    },
});

export const selectWithdrawalsData = (state) => state.withdrawals.data;
export const selectWithdrawalsLoading = (state) => state.withdrawals.loading;
export default withdrawalsSlice.reducer;
```

#### `src/store/slices/transactionsSlice.js`

```javascript
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import apiClient from "../../api/client";

export const fetchTransactions = createAsyncThunk(
    "transactions/fetch",
    async (params = {}, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/transactions", { params });
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const fetchTransactionDetail = createAsyncThunk(
    "transactions/fetchDetail",
    async (id, { rejectWithValue }) => {
        try {
            const res = await apiClient.get(`/member/transactions/${id}`);
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

const transactionsSlice = createSlice({
    name: "transactions",
    initialState: {
        data: null, // { transactions, total_credits, total_debits, net_balance }
        detail: null,
        loading: false,
    },
    reducers: {
        clearTransactionDetail: (state) => {
            state.detail = null;
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchTransactions.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchTransactions.fulfilled, (state, action) => {
                state.loading = false;
                state.data = action.payload;
            })
            .addCase(fetchTransactions.rejected, (state) => {
                state.loading = false;
            })
            .addCase(fetchTransactionDetail.fulfilled, (state, action) => {
                state.detail = action.payload;
            });
    },
});

export const { clearTransactionDetail } = transactionsSlice.actions;
export const selectTransactionsData = (state) => state.transactions.data;
export const selectTransactionDetail = (state) => state.transactions.detail;
export const selectTransactionsLoading = (state) => state.transactions.loading;
export default transactionsSlice.reducer;
```

#### `src/store/slices/commoditiesSlice.js`

```javascript
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import apiClient from "../../api/client";

export const fetchCommodities = createAsyncThunk(
    "commodities/fetch",
    async (_, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/commodities");
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const fetchCommodityDetail = createAsyncThunk(
    "commodities/detail",
    async (id, { rejectWithValue }) => {
        try {
            const res = await apiClient.get(`/member/commodities/${id}`);
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const subscribeCommodity = createAsyncThunk(
    "commodities/subscribe",
    async ({ commodityId, ...data }, { rejectWithValue }) => {
        try {
            const res = await apiClient.post(
                `/member/commodities/${commodityId}/subscribe`,
                data,
            );
            return res.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const fetchSubscriptions = createAsyncThunk(
    "commodities/subscriptions",
    async (_, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/commodity-subscriptions");
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const fetchSubscriptionDetail = createAsyncThunk(
    "commodities/subscriptionDetail",
    async (id, { rejectWithValue }) => {
        try {
            const res = await apiClient.get(
                `/member/commodity-subscriptions/${id}`,
            );
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const makePayment = createAsyncThunk(
    "commodities/pay",
    async ({ subscriptionId, ...data }, { rejectWithValue }) => {
        try {
            const res = await apiClient.post(
                `/member/commodity-subscriptions/${subscriptionId}/payments`,
                data,
            );
            return res.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

const commoditiesSlice = createSlice({
    name: "commodities",
    initialState: {
        list: null,
        detail: null,
        subscriptions: null,
        subscriptionDetail: null,
        loading: false,
    },
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(fetchCommodities.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchCommodities.fulfilled, (state, action) => {
                state.loading = false;
                state.list = action.payload;
            })
            .addCase(fetchCommodities.rejected, (state) => {
                state.loading = false;
            })
            .addCase(fetchCommodityDetail.fulfilled, (state, action) => {
                state.detail = action.payload;
            })
            .addCase(fetchSubscriptions.fulfilled, (state, action) => {
                state.subscriptions = action.payload;
            })
            .addCase(fetchSubscriptionDetail.fulfilled, (state, action) => {
                state.subscriptionDetail = action.payload;
            });
    },
});

export const selectCommodities = (state) => state.commodities.list;
export const selectCommodityDetail = (state) => state.commodities.detail;
export const selectSubscriptions = (state) => state.commodities.subscriptions;
export const selectSubscriptionDetail = (state) =>
    state.commodities.subscriptionDetail;
export const selectCommoditiesLoading = (state) => state.commodities.loading;
export default commoditiesSlice.reducer;
```

#### `src/store/slices/notificationsSlice.js`

```javascript
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import apiClient from "../../api/client";

export const fetchNotifications = createAsyncThunk(
    "notifications/fetch",
    async (_, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/notifications");
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const markRead = createAsyncThunk(
    "notifications/markRead",
    async (id, { rejectWithValue }) => {
        try {
            await apiClient.post(`/member/notifications/${id}/read`);
            return id;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

export const markAllRead = createAsyncThunk(
    "notifications/markAllRead",
    async (_, { rejectWithValue }) => {
        try {
            await apiClient.post("/member/notifications/read-all");
            return true;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

const notificationsSlice = createSlice({
    name: "notifications",
    initialState: { data: null, loading: false },
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(fetchNotifications.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchNotifications.fulfilled, (state, action) => {
                state.loading = false;
                state.data = action.payload;
            })
            .addCase(fetchNotifications.rejected, (state) => {
                state.loading = false;
            })
            .addCase(markRead.fulfilled, (state, action) => {
                // Optimistically update the notification
                const items = state.data?.data;
                if (items) {
                    const item = items.find((n) => n.id === action.payload);
                    if (item) item.read_at = new Date().toISOString();
                }
            })
            .addCase(markAllRead.fulfilled, (state) => {
                const items = state.data?.data;
                if (items) {
                    items.forEach((n) => {
                        n.read_at = n.read_at || new Date().toISOString();
                    });
                }
            });
    },
});

export const selectNotifications = (state) => state.notifications.data;
export const selectNotificationsLoading = (state) =>
    state.notifications.loading;
export const selectUnreadCount = (state) => {
    const items = state.notifications.data?.data;
    return items ? items.filter((n) => !n.read_at).length : 0;
};
export default notificationsSlice.reducer;
```

#### `src/store/slices/financialSlice.js`

```javascript
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import apiClient from "../../api/client";

export const fetchFinancialSummary = createAsyncThunk(
    "financial/fetch",
    async (year, { rejectWithValue }) => {
        try {
            const res = await apiClient.get("/member/financial-summary", {
                params: { year },
            });
            return res.data.data;
        } catch (err) {
            return rejectWithValue(err.response?.data);
        }
    },
);

const financialSlice = createSlice({
    name: "financial",
    initialState: { data: null, loading: false },
    reducers: {},
    extraReducers: (builder) => {
        builder
            .addCase(fetchFinancialSummary.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchFinancialSummary.fulfilled, (state, action) => {
                state.loading = false;
                state.data = action.payload;
            })
            .addCase(fetchFinancialSummary.rejected, (state) => {
                state.loading = false;
            });
    },
});

export const selectFinancialData = (state) => state.financial.data;
export const selectFinancialLoading = (state) => state.financial.loading;
export default financialSlice.reducer;
```

#### Updated `src/store/index.js` (add all member slices)

```javascript
import { configureStore, combineReducers } from "@reduxjs/toolkit";
import {
    persistStore,
    persistReducer,
    FLUSH,
    REHYDRATE,
    PAUSE,
    PERSIST,
    PURGE,
    REGISTER,
} from "redux-persist";
import AsyncStorage from "@react-native-async-storage/async-storage";

import authReducer from "./slices/authSlice";
import lookupReducer from "./slices/lookupSlice";
import dashboardReducer from "./slices/dashboardSlice";
import savingsReducer from "./slices/savingsSlice";
import sharesReducer from "./slices/sharesSlice";
import loansReducer from "./slices/loansSlice";
import withdrawalsReducer from "./slices/withdrawalsSlice";
import transactionsReducer from "./slices/transactionsSlice";
import commoditiesReducer from "./slices/commoditiesSlice";
import notificationsReducer from "./slices/notificationsSlice";
import financialReducer from "./slices/financialSlice";

const persistConfig = {
    key: "root",
    storage: AsyncStorage,
    whitelist: ["auth"], // only persist auth (token, user, role)
};

const rootReducer = combineReducers({
    auth: authReducer,
    lookup: lookupReducer,
    dashboard: dashboardReducer,
    savings: savingsReducer,
    shares: sharesReducer,
    loans: loansReducer,
    withdrawals: withdrawalsReducer,
    transactions: transactionsReducer,
    commodities: commoditiesReducer,
    notifications: notificationsReducer,
    financial: financialReducer,
});

const persistedReducer = persistReducer(persistConfig, rootReducer);

export const store = configureStore({
    reducer: persistedReducer,
    middleware: (getDefaultMiddleware) =>
        getDefaultMiddleware({
            serializableCheck: {
                ignoredActions: [
                    FLUSH,
                    REHYDRATE,
                    PAUSE,
                    PERSIST,
                    PURGE,
                    REGISTER,
                ],
            },
        }),
});

export const persistor = persistStore(store);
```

> **Interview note:** Only `auth` is in the persist whitelist. Member module data (savings, loans, etc.) is fetched fresh on each screen mount, keeping the app data always current without stale cache issues.

### Screen Implementation Pattern (copy this for each module)

```javascript
// Example: src/screens/member/SavingsScreen.js
import React, { useEffect, useCallback } from "react";
import { View, Text, FlatList, RefreshControl, StyleSheet } from "react-native";
import { useDispatch, useSelector } from "react-redux";
import {
    fetchSavings,
    selectSavingsData,
    selectSavingsLoading,
} from "../../store/slices/savingsSlice";
import Card from "../../components/Card";
import LoadingSpinner from "../../components/LoadingSpinner";
import EmptyState from "../../components/EmptyState";
import { COLORS } from "../../utils/theme";
import { formatCurrency } from "../../utils/formatters";

export default function SavingsScreen({ navigation }) {
    const dispatch = useDispatch();
    const data = useSelector(selectSavingsData);
    const loading = useSelector(selectSavingsLoading);

    const loadData = useCallback(() => {
        dispatch(fetchSavings());
    }, [dispatch]);

    useEffect(() => {
        loadData();
    }, [loadData]);

    if (!data && loading) return <LoadingSpinner />;

    return (
        <View style={styles.container}>
            {/* Summary Cards */}
            <View style={styles.cardsRow}>
                {data?.type_balances?.map((type) => (
                    <Card key={type.id} style={styles.card}>
                        <Text style={styles.cardLabel}>{type.name}</Text>
                        <Text style={styles.cardValue}>
                            {formatCurrency(type.amount)}
                        </Text>
                    </Card>
                ))}
                <Card style={styles.card}>
                    <Text style={styles.cardLabel}>Savings Balance</Text>
                    <Text style={[styles.cardValue, { color: COLORS.success }]}>
                        {formatCurrency(data?.savings_balance)}
                    </Text>
                </Card>
            </View>

            {/* Savings List */}
            <FlatList
                data={data?.recent_savings?.data || []}
                keyExtractor={(item) => item.id.toString()}
                refreshControl={
                    <RefreshControl
                        refreshing={loading}
                        onRefresh={loadData}
                        colors={[COLORS.primary]}
                    />
                }
                ListEmptyComponent={
                    <EmptyState message="No savings records yet" />
                }
                renderItem={({ item }) => (
                    <View style={styles.row}>
                        <View>
                            <Text style={styles.rowTitle}>
                                {item.saving_type?.name}
                            </Text>
                            <Text style={styles.rowDate}>
                                {new Date(item.created_at).toLocaleDateString()}
                            </Text>
                        </View>
                        <Text style={styles.rowAmount}>
                            {formatCurrency(item.amount)}
                        </Text>
                    </View>
                )}
            />
        </View>
    );
}

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: COLORS.background, padding: 16 },
    cardsRow: {
        flexDirection: "row",
        flexWrap: "wrap",
        gap: 12,
        marginBottom: 16,
    },
    card: { flex: 1, minWidth: "45%", padding: 16 },
    cardLabel: { fontSize: 13, color: COLORS.gray500 },
    cardValue: {
        fontSize: 22,
        fontWeight: "bold",
        color: COLORS.primary,
        marginTop: 4,
    },
    row: {
        flexDirection: "row",
        justifyContent: "space-between",
        alignItems: "center",
        backgroundColor: COLORS.white,
        padding: 16,
        borderRadius: 8,
        marginBottom: 8,
    },
    rowTitle: { fontSize: 15, fontWeight: "600", color: COLORS.gray700 },
    rowDate: { fontSize: 12, color: COLORS.gray500, marginTop: 2 },
    rowAmount: { fontSize: 16, fontWeight: "bold", color: COLORS.primary },
});
```

> **Pattern replicated for every module screen:**
>
> 1. `useEffect` → `dispatch(fetchXxx())` on mount
> 2. `useSelector` → read data + loading
> 3. `RefreshControl` → pull-to-refresh
> 4. `LoadingSpinner` / `EmptyState` for empty/loading states
> 5. Navigation to detail screens via `navigation.navigate("LoanDetail", { id })`

### Endpoint → Screen → Slice Mapping (complete reference)

| API Endpoint                                         | Screen File                       | Slice                | Thunk                        |
| ---------------------------------------------------- | --------------------------------- | -------------------- | ---------------------------- |
| `GET /member/dashboard`                              | `DashboardScreen.js`              | `dashboardSlice`     | `fetchDashboard`             |
| `GET /member/savings`                                | `SavingsScreen.js`                | `savingsSlice`       | `fetchSavings`               |
| `GET /member/savings/monthly-summary`                | `SavingsMonthlySummaryScreen.js`  | `savingsSlice`       | `fetchSavingsMonthlySummary` |
| `GET /member/savings-settings`                       | `SavingsSettingsScreen.js`        | `savingsSlice`       | `fetchSavingsSettings`       |
| `POST /member/savings-settings`                      | `SavingsSettingsScreen.js`        | `savingsSlice`       | `createSavingsSetting`       |
| `GET /member/shares`                                 | `SharesScreen.js`                 | `sharesSlice`        | `fetchShares`                |
| `POST /member/shares`                                | `SharesScreen.js`                 | `sharesSlice`        | `purchaseShare`              |
| `GET /member/loans`                                  | `LoansScreen.js`                  | `loansSlice`         | `fetchLoans`                 |
| `GET /member/loans/{id}`                             | `LoanDetailScreen.js`             | `loansSlice`         | `fetchLoanDetail`            |
| `POST /member/loans`                                 | `LoanApplyScreen.js`              | `loansSlice`         | `applyForLoan`               |
| `POST /member/loan-calculator`                       | `LoanCalculatorScreen.js`         | `loansSlice`         | `calculateLoan`              |
| `GET /member/members?search=`                        | `LoanApplyScreen.js`              | `loansSlice`         | `searchMembers`              |
| `GET /member/guarantor-requests`                     | `GuarantorRequestsScreen.js`      | `loansSlice`         | `fetchGuarantorRequests`     |
| `POST /member/guarantor-requests/{id}/respond`       | `GuarantorRequestsScreen.js`      | `loansSlice`         | `respondGuarantor`           |
| `GET /member/withdrawals`                            | `WithdrawalsScreen.js`            | `withdrawalsSlice`   | `fetchWithdrawals`           |
| `POST /member/withdrawals`                           | `WithdrawalsScreen.js`            | `withdrawalsSlice`   | `requestWithdrawal`          |
| `GET /member/transactions`                           | `PassbookScreen.js`               | `transactionsSlice`  | `fetchTransactions`          |
| `GET /member/transactions/{id}`                      | `TransactionDetailScreen.js`      | `transactionsSlice`  | `fetchTransactionDetail`     |
| `GET /member/commodities`                            | `CommoditiesScreen.js`            | `commoditiesSlice`   | `fetchCommodities`           |
| `GET /member/commodities/{id}`                       | `CommodityDetailScreen.js`        | `commoditiesSlice`   | `fetchCommodityDetail`       |
| `POST /member/commodities/{id}/subscribe`            | `CommodityDetailScreen.js`        | `commoditiesSlice`   | `subscribeCommodity`         |
| `GET /member/commodity-subscriptions`                | `CommoditySubscriptionsScreen.js` | `commoditiesSlice`   | `fetchSubscriptions`         |
| `GET /member/commodity-subscriptions/{id}`           | `CommoditySubscriptionsScreen.js` | `commoditiesSlice`   | `fetchSubscriptionDetail`    |
| `POST /member/commodity-subscriptions/{id}/payments` | `CommoditySubscriptionsScreen.js` | `commoditiesSlice`   | `makePayment`                |
| `GET /member/notifications`                          | `NotificationsScreen.js`          | `notificationsSlice` | `fetchNotifications`         |
| `POST /member/notifications/{id}/read`               | `NotificationsScreen.js`          | `notificationsSlice` | `markRead`                   |
| `POST /member/notifications/read-all`                | `NotificationsScreen.js`          | `notificationsSlice` | `markAllRead`                |
| `GET /member/financial-summary`                      | `FinancialSummaryScreen.js`       | `financialSlice`     | `fetchFinancialSummary`      |
| `PUT /member/profile`                                | `ProfileScreen.js`                | `authSlice`          | `fetchProfile` (after save)  |
| `GET /member/resources`                              | `ResourcesScreen.js`              | _(inline fetch)_     | _(simple useEffect)_         |

---

## Testing the API

### Using cURL

```bash
# Login
curl -X POST https://your-domain.com/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email":"john@example.com","password":"secret123","device_name":"curl"}'

# Get profile (use token from login response)
curl https://your-domain.com/api/v1/me \
  -H "Authorization: Bearer 1|abc123def456..."

# List states
curl https://your-domain.com/api/v1/states
```

### Using Postman

1. Import the base URL: `https://your-domain.com/api/v1`
2. Create an environment variable `{{token}}`
3. After login, set `{{token}}` from the response
4. Add header `Authorization: Bearer {{token}}` to protected requests

---

## CORS Configuration

If the mobile app hits the API from a different origin during development, ensure CORS is configured in `config/cors.php`:

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_origins' => ['*'], // Restrict in production
```

---

## Rate Limiting

Laravel's default API rate limiter applies: **60 requests per minute** per user/IP. The API returns `429 Too Many Requests` when exceeded.

---

## Files Created / Modified

| File                                               | Action                     | Purpose                                                      |
| -------------------------------------------------- | -------------------------- | ------------------------------------------------------------ |
| `app/Http/Controllers/Api/V1/AuthController.php`   | **Created**                | API auth: login, register, logout, profile, password, lookup |
| `routes/api.php`                                   | **Modified**               | API v1 routes (public + protected + 35 member routes)        |
| `bootstrap/app.php`                                | **Modified**               | Registered `api.php` routing + `ability` middleware alias    |
| `config/auth.php`                                  | **Modified**               | Added `sanctum` guard                                        |
| `app/Models/User.php`                              | **Modified**               | Added `HasApiTokens` trait                                   |
| `app/Http/Controllers/Api/V1/MemberController.php` | **Created**                | All member API endpoints (dashboard, savings, loans, etc.)   |
| `composer.json`                                    | **Modified** (by composer) | Added `laravel/sanctum` dependency                           |
| `docs/API.md`                                      | **Created**                | This documentation                                           |

---

## What's Next?

1. ~~**Phase 1 – Auth Endpoints:** Login, register, logout, profile, password reset~~ ✅ Done
2. ~~**Phase 2 – Member Endpoints:** Dashboard, savings, shares, loans, passbook, withdrawals, commodities, guarantor, notifications, financial summary, profile, resources~~ ✅ Done (35 routes)
3. **Phase 3 – Admin Endpoints:** Member management, financial CRUD, reports, role management
4. **Phase 4 – Push Notifications:** Expo Push Notifications + Firebase Cloud Messaging
5. **Phase 5 – File Downloads:** PDF generation for passbook, financial summaries

---

## Redux Cheat Sheet for Interviews

Key concepts demonstrated in this project that interviewers commonly ask about:

### Core Redux Toolkit APIs Used

| API                | Purpose                                                        | File                            |
| ------------------ | -------------------------------------------------------------- | ------------------------------- |
| `configureStore`   | Creates the Redux store with middleware                        | `src/store/index.js`            |
| `createSlice`      | Defines reducers + actions in one place                        | `src/store/slices/authSlice.js` |
| `createAsyncThunk` | Handles async API calls with pending/fulfilled/rejected        | `src/store/slices/authSlice.js` |
| `useSelector`      | Read state in components (replaces `mapStateToProps`)          | All screens                     |
| `useDispatch`      | Dispatch actions in components (replaces `mapDispatchToProps`) | All screens                     |
| `combineReducers`  | Combine multiple slices into one root reducer                  | `src/store/index.js`            |

### Redux Persist

| Concept       | Explanation                                                                               |
| ------------- | ----------------------------------------------------------------------------------------- |
| **Why?**      | Auth token must survive app restart without re-login                                      |
| **How?**      | `persistReducer` wraps the root reducer, `PersistGate` delays render until rehydrated     |
| **Whitelist** | Only `auth` slice is persisted (token, user, role). Lookup data is fetched fresh.         |
| **Storage**   | `@react-native-async-storage/async-storage` (React Native’s equivalent of `localStorage`) |

### Common Interview Questions & Answers

**Q: Why Redux Toolkit over plain Redux?**

> RTK eliminates boilerplate: `createSlice` auto-generates action creators, `createAsyncThunk` handles async lifecycle, and `configureStore` sets up the store with good defaults (including Redux DevTools and thunk middleware).

**Q: How does `createAsyncThunk` work?**

> It accepts a string action type and an async function. It automatically dispatches `pending`, `fulfilled`, or `rejected` actions based on the promise outcome. These are handled in `extraReducers`.

**Q: Why `rejectWithValue` instead of throwing?**

> `rejectWithValue` lets you pass a custom payload (like API error messages) to the `rejected` case, instead of getting a serialized error object.

**Q: How do you handle token refresh?**

> Our Axios interceptor reads the token from `store.getState().auth.token` synchronously. On 401, it dispatches `logout()` which clears the store. Redux Persist auto-clears AsyncStorage.

**Q: What’s the difference between `reducers` and `extraReducers` in createSlice?**

> `reducers` defines synchronous actions (like `logout`, `clearError`). `extraReducers` responds to actions from `createAsyncThunk` or other slices.

Let us know when you're ready to proceed with Phase 2!
