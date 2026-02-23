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

## Available Modules – Admin (92 Routes)

> **Auth:** All admin endpoints require a Sanctum token with `admin` ability.
> Header: `Authorization: Bearer <admin-token>`
> Base URL: `/api/v1/admin`

### Admin Route Summary Table

| #   | Module             | Method | Endpoint                                                | Controller Method              |
| --- | ------------------ | ------ | ------------------------------------------------------- | ------------------------------ |
| 1   | Dashboard          | GET    | `/admin/dashboard`                                      | `dashboard`                    |
| 2   | Lookup             | GET    | `/admin/lookup/months`                                  | `lookupMonths`                 |
| 3   | Lookup             | GET    | `/admin/lookup/years`                                   | `lookupYears`                  |
| 4   | Lookup             | GET    | `/admin/lookup/members`                                 | `lookupMembers`                |
| 5   | Members            | GET    | `/admin/members`                                        | `members`                      |
| 6   | Members            | POST   | `/admin/members`                                        | `storeMember`                  |
| 7   | Members            | GET    | `/admin/members/{member}`                               | `showMember`                   |
| 8   | Members            | PUT    | `/admin/members/{member}`                               | `updateMember`                 |
| 9   | Members            | DELETE | `/admin/members/{member}`                               | `destroyMember`                |
| 10  | Members            | POST   | `/admin/members/{member}/approve`                       | `approveMember`                |
| 11  | Members            | POST   | `/admin/members/{member}/reject`                        | `rejectMember`                 |
| 12  | Members            | POST   | `/admin/members/{member}/suspend`                       | `suspendMember`                |
| 13  | Members            | POST   | `/admin/members/{member}/activate`                      | `activateMember`               |
| 14  | Entrance Fees      | GET    | `/admin/entrance-fees`                                  | `entranceFees`                 |
| 15  | Entrance Fees      | POST   | `/admin/entrance-fees`                                  | `storeEntranceFee`             |
| 16  | Entrance Fees      | PUT    | `/admin/entrance-fees/{entranceFee}`                    | `updateEntranceFee`            |
| 17  | Entrance Fees      | DELETE | `/admin/entrance-fees/{entranceFee}`                    | `destroyEntranceFee`           |
| 18  | Saving Types       | GET    | `/admin/saving-types`                                   | `savingTypes`                  |
| 19  | Saving Types       | POST   | `/admin/saving-types`                                   | `storeSavingType`              |
| 20  | Saving Types       | PUT    | `/admin/saving-types/{savingType}`                      | `updateSavingType`             |
| 21  | Savings            | GET    | `/admin/savings`                                        | `savings`                      |
| 22  | Savings            | POST   | `/admin/savings`                                        | `storeSaving`                  |
| 23  | Savings            | PUT    | `/admin/savings/{saving}`                               | `updateSaving`                 |
| 24  | Savings            | DELETE | `/admin/savings/{saving}`                               | `destroySaving`                |
| 25  | Savings Settings   | GET    | `/admin/savings-settings`                               | `savingsSettings`              |
| 26  | Savings Settings   | POST   | `/admin/savings-settings/{setting}/approve`             | `approveSavingsSetting`        |
| 27  | Savings Settings   | POST   | `/admin/savings-settings/{setting}/reject`              | `rejectSavingsSetting`         |
| 28  | Share Types        | GET    | `/admin/share-types`                                    | `shareTypes`                   |
| 29  | Share Types        | POST   | `/admin/share-types`                                    | `storeShareType`               |
| 30  | Share Types        | PUT    | `/admin/share-types/{shareType}`                        | `updateShareType`              |
| 31  | Share Types        | DELETE | `/admin/share-types/{shareType}`                        | `destroyShareType`             |
| 32  | Shares             | GET    | `/admin/shares`                                         | `shares`                       |
| 33  | Shares             | POST   | `/admin/shares`                                         | `storeShare`                   |
| 34  | Shares             | POST   | `/admin/shares/{share}/approve`                         | `approveShare`                 |
| 35  | Shares             | POST   | `/admin/shares/{share}/reject`                          | `rejectShare`                  |
| 36  | Shares             | DELETE | `/admin/shares/{share}`                                 | `destroyShare`                 |
| 37  | Loan Types         | GET    | `/admin/loan-types`                                     | `loanTypes`                    |
| 38  | Loan Types         | POST   | `/admin/loan-types`                                     | `storeLoanType`                |
| 39  | Loan Types         | PUT    | `/admin/loan-types/{loanType}`                          | `updateLoanType`               |
| 40  | Loan Types         | DELETE | `/admin/loan-types/{loanType}`                          | `destroyLoanType`              |
| 41  | Loans              | GET    | `/admin/loans`                                          | `loans`                        |
| 42  | Loans              | POST   | `/admin/loans`                                          | `storeLoan`                    |
| 43  | Loans              | GET    | `/admin/loans/{loan}`                                   | `showLoan`                     |
| 44  | Loans              | POST   | `/admin/loans/{loan}/approve`                           | `approveLoan`                  |
| 45  | Loans              | POST   | `/admin/loans/{loan}/reject`                            | `rejectLoan`                   |
| 46  | Loan Repayments    | GET    | `/admin/loan-repayments`                                | `loanRepayments`               |
| 47  | Loan Repayments    | POST   | `/admin/loan-repayments/{loan}`                         | `storeLoanRepayment`           |
| 48  | Withdrawals        | GET    | `/admin/withdrawals`                                    | `withdrawals`                  |
| 49  | Withdrawals        | POST   | `/admin/withdrawals`                                    | `storeWithdrawal`              |
| 50  | Withdrawals        | POST   | `/admin/withdrawals/{withdrawal}/approve`               | `approveWithdrawal`            |
| 51  | Withdrawals        | POST   | `/admin/withdrawals/{withdrawal}/reject`                | `rejectWithdrawal`             |
| 52  | Transactions       | GET    | `/admin/transactions`                                   | `transactions`                 |
| 53  | Transactions       | GET    | `/admin/transactions/{transaction}`                     | `showTransaction`              |
| 54  | Transactions       | DELETE | `/admin/transactions/{transaction}`                     | `destroyTransaction`           |
| 55  | Commodities        | GET    | `/admin/commodities`                                    | `commodities`                  |
| 56  | Commodities        | POST   | `/admin/commodities`                                    | `storeCommodity`               |
| 57  | Commodities        | GET    | `/admin/commodities/{commodity}`                        | `showCommodity`                |
| 58  | Commodities        | PUT    | `/admin/commodities/{commodity}`                        | `updateCommodity`              |
| 59  | Commodities        | DELETE | `/admin/commodities/{commodity}`                        | `destroyCommodity`             |
| 60  | Commodity Subs     | GET    | `/admin/commodity-subscriptions`                        | `commoditySubscriptions`       |
| 61  | Commodity Subs     | GET    | `/admin/commodity-subscriptions/{subscription}`         | `showCommoditySubscription`    |
| 62  | Commodity Subs     | POST   | `/admin/commodity-subscriptions/{subscription}/approve` | `approveCommoditySubscription` |
| 63  | Commodity Subs     | POST   | `/admin/commodity-subscriptions/{subscription}/reject`  | `rejectCommoditySubscription`  |
| 64  | Commodity Payments | GET    | `/admin/commodity-payments`                             | `commodityPayments`            |
| 65  | Commodity Payments | POST   | `/admin/commodity-payments/{subscription}`              | `storeCommodityPayment`        |
| 66  | Commodity Payments | POST   | `/admin/commodity-payments/{payment}/approve`           | `approveCommodityPayment`      |
| 67  | Commodity Payments | POST   | `/admin/commodity-payments/{payment}/reject`            | `rejectCommodityPayment`       |
| 68  | Profile Requests   | GET    | `/admin/profile-requests`                               | `profileUpdateRequests`        |
| 69  | Profile Requests   | GET    | `/admin/profile-requests/{profileRequest}`              | `showProfileUpdateRequest`     |
| 70  | Profile Requests   | POST   | `/admin/profile-requests/{profileRequest}/approve`      | `approveProfileUpdate`         |
| 71  | Profile Requests   | POST   | `/admin/profile-requests/{profileRequest}/reject`       | `rejectProfileUpdate`          |
| 72  | Resources          | GET    | `/admin/resources`                                      | `resources`                    |
| 73  | Resources          | POST   | `/admin/resources`                                      | `storeResource`                |
| 74  | Resources          | DELETE | `/admin/resources/{resource}`                           | `destroyResource`              |
| 75  | FAQs               | GET    | `/admin/faqs`                                           | `faqs`                         |
| 76  | FAQs               | POST   | `/admin/faqs`                                           | `storeFaq`                     |
| 77  | FAQs               | PUT    | `/admin/faqs/{faq}`                                     | `updateFaq`                    |
| 78  | FAQs               | DELETE | `/admin/faqs/{faq}`                                     | `destroyFaq`                   |
| 79  | Admin Users        | GET    | `/admin/admins`                                         | `admins`                       |
| 80  | Admin Users        | POST   | `/admin/admins`                                         | `storeAdmin`                   |
| 81  | Roles              | GET    | `/admin/roles`                                          | `roles`                        |
| 82  | Roles              | POST   | `/admin/roles`                                          | `storeRole`                    |
| 83  | Roles              | PUT    | `/admin/roles/{role}`                                   | `updateRole`                   |
| 84  | Permissions        | GET    | `/admin/permissions`                                    | `permissions`                  |
| 85  | Financial          | GET    | `/admin/financial-summary`                              | `financialSummary`             |
| 86  | Financial          | GET    | `/admin/financial-summary/{member}`                     | `memberFinancialSummary`       |
| 87  | Reports            | GET    | `/admin/reports/members`                                | `reportMembers`                |
| 88  | Reports            | GET    | `/admin/reports/savings`                                | `reportSavings`                |
| 89  | Reports            | GET    | `/admin/reports/shares`                                 | `reportShares`                 |
| 90  | Reports            | GET    | `/admin/reports/loans`                                  | `reportLoans`                  |
| 91  | Reports            | GET    | `/admin/reports/transactions`                           | `reportTransactions`           |
| 92  | Reports            | GET    | `/admin/reports/savings-summary`                        | `reportSavingsSummary`         |

---

### 1 · Admin Dashboard

#### `GET /api/v1/admin/dashboard`

Returns system-wide statistics and chart data.

**Response:**

```json
{
    "success": true,
    "data": {
        "total_members": 245,
        "new_members_this_month": 12,
        "total_admins": 5,
        "total_savings": 15000000.0,
        "monthly_savings": 1250000.0,
        "saving_balance": 12500000.0,
        "total_shares": 5000000.0,
        "total_share_units": 320,
        "active_loans": 45,
        "total_loan_amount": 20000000.0,
        "total_repayments": 8000000.0,
        "outstanding_loans": 12000000.0,
        "total_withdrawals": 2500000.0,
        "pending_withdrawals": 8,
        "total_commodities": 15,
        "total_resources": 10,
        "monthly_data": [
            {
                "month": "Jan",
                "savings": 1000000,
                "loans": 500000,
                "shares": 200000,
                "withdrawals": 100000
            },
            {
                "month": "Feb",
                "savings": 1100000,
                "loans": 600000,
                "shares": 250000,
                "withdrawals": 120000
            }
        ],
        "recent_members": [
            {
                "id": 1,
                "surname": "Doe",
                "firstname": "John",
                "member_no": "MEM001",
                "email": "john@example.com",
                "created_at": "2024-01-15"
            }
        ],
        "recent_transactions": [
            {
                "id": 1,
                "type": "savings",
                "credit_amount": 50000,
                "user": { "id": 1, "surname": "Doe", "firstname": "John" }
            }
        ],
        "pending_loans": [
            {
                "id": 1,
                "reference": "LOAN-2024-abc",
                "amount": 500000,
                "user": { "id": 1, "surname": "Doe" },
                "loan_type": { "name": "Normal Loan" }
            }
        ]
    }
}
```

---

### 2 · Admin Lookups

#### `GET /api/v1/admin/lookup/months`

Returns all months (for dropdowns/pickers).

```json
{
    "success": true,
    "data": [
        { "id": 1, "name": "January" },
        { "id": 2, "name": "February" }
    ]
}
```

#### `GET /api/v1/admin/lookup/years`

Returns all years descending.

```json
{
    "success": true,
    "data": [
        { "id": 5, "year": 2025 },
        { "id": 4, "year": 2024 }
    ]
}
```

#### `GET /api/v1/admin/lookup/members?search=john`

Returns approved members for selection dropdowns.

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "surname": "Doe",
            "firstname": "John",
            "member_no": "MEM001",
            "monthly_savings": 5000
        }
    ]
}
```

---

### 3 · Members Management

#### `GET /api/v1/admin/members`

| Param      | Type   | Description                   |
| ---------- | ------ | ----------------------------- |
| `search`   | string | Search name, email, member_no |
| `status`   | string | `approved` or `pending`       |
| `per_page` | int    | Items per page (default 15)   |

**Response:**

```json
{
  "success": true,
  "data": {
    "members": { "data": [...], "current_page": 1, "last_page": 10, "total": 150 },
    "total_members": 245,
    "approved_this_month": 12
  }
}
```

#### `POST /api/v1/admin/members`

Create a new member (auto-approved).

| Field             | Type           | Required               |
| ----------------- | -------------- | ---------------------- |
| `surname`         | string         | Yes                    |
| `firstname`       | string         | Yes                    |
| `staff_no`        | string         | Yes                    |
| `email`           | string (email) | Yes                    |
| `phone_number`    | string         | Yes                    |
| `faculty_id`      | int            | Yes                    |
| `department_id`   | int            | Yes                    |
| `state_id`        | int            | Yes                    |
| `lga_id`          | int            | Yes                    |
| `date_join`       | date           | Yes                    |
| `monthly_savings` | numeric        | Yes                    |
| `password`        | string         | Yes (min 8, confirmed) |

#### `GET /api/v1/admin/members/{member}`

Returns full member details with relationships.

#### `PUT /api/v1/admin/members/{member}`

Update member – all fields optional (send only changed fields).

#### `POST /api/v1/admin/members/{member}/approve`

Approves pending member → generates member_no → sends activation email.

```json
{
    "success": true,
    "message": "Member approved successfully",
    "data": { "member_no": "MEM245" }
}
```

#### `POST /api/v1/admin/members/{member}/reject`

#### `POST /api/v1/admin/members/{member}/suspend`

#### `POST /api/v1/admin/members/{member}/activate`

#### `DELETE /api/v1/admin/members/{member}`

Soft-deletes member. Fails if member has active loans (422).

```json
{ "success": false, "message": "Cannot delete member with active loans" }
```

---

### 4 · Entrance Fees

#### `GET /api/v1/admin/entrance-fees`

| Param      | Type | Description     |
| ---------- | ---- | --------------- |
| `month_id` | int  | Filter by month |
| `year_id`  | int  | Filter by year  |

**Response:**

```json
{
  "success": true,
  "data": {
    "entrance_fees": { "data": [...], "current_page": 1 },
    "total_amount": 500000.00,
    "months": [...],
    "years": [...]
  }
}
```

#### `POST /api/v1/admin/entrance-fees`

| Field            | Type    | Required                                          |
| ---------------- | ------- | ------------------------------------------------- |
| `user_id`        | int     | Yes                                               |
| `amount`         | numeric | Yes                                               |
| `month_id`       | int     | Yes                                               |
| `year_id`        | int     | Yes                                               |
| `remark`         | string  | No                                                |
| `approve_member` | boolean | No (if true → auto-approves member + sends email) |

#### `PUT /api/v1/admin/entrance-fees/{entranceFee}`

#### `DELETE /api/v1/admin/entrance-fees/{entranceFee}`

---

### 5 · Saving Types

#### `GET /api/v1/admin/saving-types`

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "Regular Savings",
            "interest_rate": 5.0,
            "minimum_balance": 1000
        }
    ]
}
```

#### `POST /api/v1/admin/saving-types`

| Field                         | Type            | Required |
| ----------------------------- | --------------- | -------- |
| `name`                        | string          | Yes      |
| `description`                 | string          | No       |
| `interest_rate`               | numeric (0-100) | Yes      |
| `minimum_balance`             | numeric         | Yes      |
| `is_mandatory`                | boolean         | No       |
| `allow_withdrawal`            | boolean         | No       |
| `withdrawal_restriction_days` | int             | Yes      |

#### `PUT /api/v1/admin/saving-types/{savingType}`

Same fields as create + optional `status` (`active` / `inactive`).

---

### 6 · Savings

#### `GET /api/v1/admin/savings`

| Param      | Type | Description              |
| ---------- | ---- | ------------------------ |
| `month`    | int  | Filter by month_id       |
| `year`     | int  | Filter by year_id        |
| `type`     | int  | Filter by saving_type_id |
| `user_id`  | int  | Filter by member         |
| `per_page` | int  | Default 20               |

**Response:**

```json
{
  "success": true,
  "data": {
    "savings": { "data": [...] },
    "total_savings": 15000000.00,
    "saving_types": [...],
    "months": [...],
    "years": [...]
  }
}
```

#### `POST /api/v1/admin/savings`

| Field            | Type    | Required | Notes                                |
| ---------------- | ------- | -------- | ------------------------------------ |
| `user_id`        | int     | Yes      |                                      |
| `saving_type_id` | int     | Yes      |                                      |
| `month_id`       | int     | Yes      |                                      |
| `year_id`        | int     | Yes      |                                      |
| `amount`         | numeric | No       | Defaults to member's monthly_savings |
| `remark`         | string  | No       |                                      |

Interest is auto-calculated from the saving type's rate.

#### `PUT /api/v1/admin/savings/{saving}`

#### `DELETE /api/v1/admin/savings/{saving}`

---

### 7 · Savings Settings (Monthly Deduction Approval)

#### `GET /api/v1/admin/savings-settings`

Returns member-requested monthly savings changes (paginated).

#### `POST /api/v1/admin/savings-settings/{setting}/approve`

Approves the setting. If current month/year matches → updates member's `monthly_savings`.

#### `POST /api/v1/admin/savings-settings/{setting}/reject`

| Field         | Type   | Required      |
| ------------- | ------ | ------------- |
| `admin_notes` | string | Yes (max 500) |

---

### 8 · Share Types

#### `GET /api/v1/admin/share-types`

#### `POST /api/v1/admin/share-types`

| Field               | Type    | Required |
| ------------------- | ------- | -------- |
| `name`              | string  | Yes      |
| `minimum_amount`    | numeric | Yes      |
| `maximum_amount`    | numeric | Yes      |
| `dividend_rate`     | numeric | Yes      |
| `is_transferable`   | boolean | No       |
| `has_voting_rights` | boolean | No       |
| `description`       | string  | No       |

#### `PUT /api/v1/admin/share-types/{shareType}`

#### `DELETE /api/v1/admin/share-types/{shareType}`

Fails if shares exist for this type (422).

---

### 9 · Shares

#### `GET /api/v1/admin/shares`

| Param           | Type   | Description                       |
| --------------- | ------ | --------------------------------- |
| `share_type_id` | int    | Filter by type                    |
| `month_id`      | int    | Filter by month                   |
| `year_id`       | int    | Filter by year                    |
| `status`        | string | `pending`, `approved`, `rejected` |
| `user_id`       | int    | Filter by member                  |

**Response:**

```json
{
  "success": true,
  "data": {
    "shares": { "data": [...] },
    "total_shares": 5000000.00,
    "share_types": [...],
    "months": [...],
    "years": [...]
  }
}
```

#### `POST /api/v1/admin/shares`

| Field           | Type    | Required |
| --------------- | ------- | -------- |
| `user_id`       | int     | Yes      |
| `share_type_id` | int     | Yes      |
| `amount_paid`   | numeric | Yes      |
| `month_id`      | int     | Yes      |
| `year_id`       | int     | Yes      |
| `remark`        | string  | No       |

Admin-created shares are auto-approved with transaction recorded.

#### `POST /api/v1/admin/shares/{share}/approve`

#### `POST /api/v1/admin/shares/{share}/reject`

#### `DELETE /api/v1/admin/shares/{share}`

Only non-approved shares can be deleted.

---

### 10 · Loan Types

#### `GET /api/v1/admin/loan-types`

#### `POST /api/v1/admin/loan-types`

| Field                            | Type            | Required         |
| -------------------------------- | --------------- | ---------------- |
| `name`                           | string          | Yes              |
| `required_active_savings_months` | int             | Yes (min 3)      |
| `savings_multiplier`             | numeric         | Yes (min 1)      |
| `interest_rate`                  | numeric (0-100) | Yes              |
| `duration_months`                | int             | Yes              |
| `minimum_amount`                 | numeric         | Yes              |
| `maximum_amount`                 | numeric         | Yes (gt minimum) |
| `allow_early_payment`            | boolean         | No               |
| `no_guarantors`                  | int             | Yes              |
| `application_fee`                | numeric         | Yes              |

#### `PUT /api/v1/admin/loan-types/{loanType}`

#### `DELETE /api/v1/admin/loan-types/{loanType}`

---

### 11 · Loans

#### `GET /api/v1/admin/loans`

| Param       | Type   | Description                                    |
| ----------- | ------ | ---------------------------------------------- |
| `status`    | string | `pending`, `approved`, `rejected`, `completed` |
| `reference` | string | Filter by loan reference                       |
| `user_id`   | int    | Filter by member                               |

**Response:**

```json
{
  "success": true,
  "data": {
    "loans": { "data": [...] },
    "total_loan_amount": 20000000.00,
    "status_counts": { "pending": 5, "approved": 40, "rejected": 3, "completed": 12 }
  }
}
```

#### `POST /api/v1/admin/loans`

| Field          | Type         | Required |
| -------------- | ------------ | -------- |
| `user_id`      | int          | Yes      |
| `loan_type_id` | int          | Yes      |
| `amount`       | numeric      | Yes      |
| `duration`     | int (months) | Yes      |
| `start_date`   | date         | Yes      |
| `purpose`      | string       | Yes      |

Interest is auto-calculated: `amount × (rate/100) × (duration/12)`.

#### `GET /api/v1/admin/loans/{loan}`

Returns loan with user, loanType, guarantors, and repayments.

#### `POST /api/v1/admin/loans/{loan}/approve`

Approves loan → records disbursement + application fee transactions → notifies member.

#### `POST /api/v1/admin/loans/{loan}/reject`

---

### 12 · Loan Repayments

#### `GET /api/v1/admin/loan-repayments`

Returns active/approved loans with balance > 0, including `remaining_months` and `is_overdue`.

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "reference": "LOAN-2024-abc",
      "amount": 500000,
      "balance": 300000,
      "remaining_months": 6,
      "is_overdue": false,
      "user": { "surname": "Doe", "firstname": "John" },
      "repayments": [...]
    }
  ]
}
```

#### `POST /api/v1/admin/loan-repayments/{loan}`

| Field            | Type    | Required |
| ---------------- | ------- | -------- |
| `amount`         | numeric | Yes      |
| `payment_date`   | date    | Yes      |
| `payment_method` | string  | Yes      |
| `notes`          | string  | No       |
| `month_id`       | int     | Yes      |
| `year_id`        | int     | Yes      |

Fails if repayment already exists for same month/year (422). Auto-updates loan balance; marks loan as `completed` when fully paid.

---

### 13 · Withdrawals

#### `GET /api/v1/admin/withdrawals`

| Param            | Type   | Description                                    |
| ---------------- | ------ | ---------------------------------------------- |
| `user_id`        | int    | Filter by member                               |
| `status`         | string | `pending`, `approved`, `rejected`, `completed` |
| `saving_type_id` | int    | Filter by saving type                          |
| `month_id`       | int    | Filter by month                                |
| `year_id`        | int    | Filter by year                                 |

**Response:**

```json
{
  "success": true,
  "data": {
    "withdrawals": { "data": [...] },
    "total_amount": 2500000.00,
    "pending_amount": 500000.00,
    "approved_amount": 2000000.00
  }
}
```

#### `POST /api/v1/admin/withdrawals`

Admin-created withdrawal (auto-completed).

| Field            | Type    | Required |
| ---------------- | ------- | -------- |
| `user_id`        | int     | Yes      |
| `saving_type_id` | int     | Yes      |
| `amount`         | numeric | Yes      |
| `bank_name`      | string  | Yes      |
| `account_number` | string  | Yes      |
| `account_name`   | string  | Yes      |
| `reason`         | string  | Yes      |
| `month_id`       | int     | Yes      |
| `year_id`        | int     | Yes      |

#### `POST /api/v1/admin/withdrawals/{withdrawal}/approve`

Fails if already processed (422). Records transaction on approval.

#### `POST /api/v1/admin/withdrawals/{withdrawal}/reject`

| Field              | Type   | Required      |
| ------------------ | ------ | ------------- |
| `rejection_reason` | string | Yes (max 500) |

---

### 14 · Transactions

#### `GET /api/v1/admin/transactions`

| Param        | Type   | Description             |
| ------------ | ------ | ----------------------- |
| `user_id`    | int    | Filter by member        |
| `type`       | string | Transaction type filter |
| `start_date` | date   | Filter from date        |
| `end_date`   | date   | Filter to date          |

**Response:**

```json
{
  "success": true,
  "data": {
    "transactions": { "data": [...] },
    "total_credits": 15000000.00,
    "total_debits": 2500000.00
  }
}
```

#### `GET /api/v1/admin/transactions/{transaction}`

#### `DELETE /api/v1/admin/transactions/{transaction}`

---

### 15 · Commodities

#### `GET /api/v1/admin/commodities`

| Param    | Type    | Description           |
| -------- | ------- | --------------------- |
| `status` | boolean | Filter by `is_active` |

#### `POST /api/v1/admin/commodities` (multipart/form-data)

| Field                        | Type    | Required                |
| ---------------------------- | ------- | ----------------------- |
| `name`                       | string  | Yes                     |
| `description`                | string  | No                      |
| `price`                      | numeric | Yes                     |
| `quantity_available`         | int     | Yes                     |
| `is_active`                  | boolean | No                      |
| `start_date`                 | date    | No                      |
| `end_date`                   | date    | No                      |
| `purchase_amount`            | numeric | No                      |
| `target_sales_amount`        | numeric | No                      |
| `profit_amount`              | numeric | No                      |
| `allow_installment`          | boolean | No                      |
| `max_installment_months`     | int     | Required if installment |
| `initial_deposit_percentage` | numeric | No (0-100)              |
| `image`                      | file    | No                      |

Monthly installment = `(price - initial_deposit) / months` (auto-calculated).

#### `GET /api/v1/admin/commodities/{commodity}`

#### `PUT /api/v1/admin/commodities/{commodity}` (multipart/form-data)

Same fields. Old image is replaced if new one uploaded.

#### `DELETE /api/v1/admin/commodities/{commodity}`

---

### 16 · Commodity Subscriptions

#### `GET /api/v1/admin/commodity-subscriptions`

#### `GET /api/v1/admin/commodity-subscriptions/{subscription}`

Includes commodity, user, and payments.

#### `POST /api/v1/admin/commodity-subscriptions/{subscription}/approve`

Fails if not enough quantity (422). Decrements commodity stock → notifies member.

#### `POST /api/v1/admin/commodity-subscriptions/{subscription}/reject`

| Field         | Type   | Required      |
| ------------- | ------ | ------------- |
| `admin_notes` | string | Yes (max 500) |

---

### 17 · Commodity Payments

#### `GET /api/v1/admin/commodity-payments`

| Param             | Type   | Description            |
| ----------------- | ------ | ---------------------- |
| `subscription_id` | int    | Filter by subscription |
| `status`          | string | Filter by status       |
| `month_id`        | int    | Filter by month        |
| `year_id`         | int    | Filter by year         |

#### `POST /api/v1/admin/commodity-payments/{subscription}`

| Field               | Type    | Required                                   |
| ------------------- | ------- | ------------------------------------------ |
| `amount`            | numeric | Yes                                        |
| `payment_method`    | string  | Yes (`cash`, `bank_transfer`, `deduction`) |
| `payment_reference` | string  | No                                         |
| `notes`             | string  | No                                         |
| `month_id`          | int     | Yes                                        |
| `year_id`           | int     | Yes                                        |

Fails if amount exceeds remaining balance (422). Auto-approved + transaction recorded.

#### `POST /api/v1/admin/commodity-payments/{payment}/approve`

#### `POST /api/v1/admin/commodity-payments/{payment}/reject`

| Field   | Type   | Required      |
| ------- | ------ | ------------- |
| `notes` | string | Yes (max 500) |

---

### 18 · Profile Update Requests

#### `GET /api/v1/admin/profile-requests`

Paginated list of member-submitted profile updates.

#### `GET /api/v1/admin/profile-requests/{profileRequest}`

#### `POST /api/v1/admin/profile-requests/{profileRequest}/approve`

Applies the requested changes to the user's profile.

#### `POST /api/v1/admin/profile-requests/{profileRequest}/reject`

| Field    | Type   | Required |
| -------- | ------ | -------- |
| `reason` | string | Yes      |

---

### 19 · Resources

#### `GET /api/v1/admin/resources`

#### `POST /api/v1/admin/resources` (multipart/form-data)

| Field         | Type   | Required       |
| ------------- | ------ | -------------- |
| `title`       | string | Yes            |
| `description` | string | No             |
| `file`        | file   | Yes (max 10MB) |

#### `DELETE /api/v1/admin/resources/{resource}`

---

### 20 · FAQs

#### `GET /api/v1/admin/faqs`

Ordered by `order` field.

#### `POST /api/v1/admin/faqs`

| Field      | Type   | Required |
| ---------- | ------ | -------- |
| `question` | string | Yes      |
| `answer`   | string | Yes      |
| `order`    | int    | No       |

#### `PUT /api/v1/admin/faqs/{faq}`

#### `DELETE /api/v1/admin/faqs/{faq}`

---

### 21 · Admin Users

#### `GET /api/v1/admin/admins`

Returns admin users with roles.

#### `POST /api/v1/admin/admins`

| Field          | Type           | Required               |
| -------------- | -------------- | ---------------------- |
| `title`        | string         | Yes                    |
| `surname`      | string         | Yes                    |
| `firstname`    | string         | Yes                    |
| `email`        | string (email) | Yes (unique)           |
| `phone_number` | string         | Yes                    |
| `password`     | string         | Yes (min 6, confirmed) |
| `roles`        | array of ints  | Yes (role IDs)         |

---

### 22 · Roles & Permissions

#### `GET /api/v1/admin/roles`

Returns roles with permissions (paginated).

#### `POST /api/v1/admin/roles`

| Field         | Type          | Required             |
| ------------- | ------------- | -------------------- |
| `name`        | string        | Yes (unique)         |
| `description` | string        | No                   |
| `permissions` | array of ints | Yes (permission IDs) |

#### `PUT /api/v1/admin/roles/{role}`

| Field         | Type          | Required |
| ------------- | ------------- | -------- |
| `name`        | string        | Yes      |
| `permissions` | array of ints | Yes      |

#### `GET /api/v1/admin/permissions`

Returns all available permissions.

---

### 23 · Financial Summary

#### `GET /api/v1/admin/financial-summary?year=2024`

Overall cooperative financial data for a year. Broken down by savings, loans, shares, commodities with monthly detail.

**Response:**

```json
{
  "success": true,
  "data": {
    "summary": {
      "savings": { "months": { "1": 500000, "2": 600000 }, "total": 15000000 },
      "loans": { "months": { "1": 200000, "2": 250000 }, "total": 8000000 },
      "shares": { "months": { "1": 100000 }, "total": 5000000 },
      "commodities": { "months": { "1": 50000 }, "total": 1000000 },
      "members": { "total": 245, "active": 200 }
    },
    "selected_year": "2024",
    "years": [...],
    "months": [...]
  }
}
```

#### `GET /api/v1/admin/financial-summary/{member}?year=2024`

Per-member financial breakdown: savings by type, loan repayments by reference, shares, commodity payments.

**Response:**

```json
{
  "success": true,
  "data": {
    "member": { "id": 1, "surname": "Doe", "firstname": "John", "member_no": "MEM001" },
    "summary": {
      "savings": {
        "1": { "name": "Regular Savings", "months": { "1": 5000, "2": 5000 }, "total": 60000 }
      },
      "loans": {
        "5": { "name": "Normal Loan (LOAN-2024-abc)", "months": { "3": 20000 }, "total": 20000 }
      },
      "shares": { "name": "Share Subscriptions", "months": { "1": 10000 }, "total": 10000 },
      "commodities": {
        "2": { "name": "Laptop (COM-001)", "months": { "1": 50000 }, "total": 50000 }
      }
    },
    "selected_year": "2024",
    "years": [...],
    "months": [...]
  }
}
```

---

### 24 · Reports

All report endpoints support filtering and return paginated data (50 per page).

#### `GET /api/v1/admin/reports/members`

| Param       | Type   | Description |
| ----------- | ------ | ----------- |
| `status`    | string | Filter      |
| `date_from` | date   | From date   |
| `date_to`   | date   | To date     |

Returns members with `shares_count`, `loans_count`, `transactions_count`.

#### `GET /api/v1/admin/reports/savings`

| Param         | Type | Description           |
| ------------- | ---- | --------------------- |
| `member_id`   | int  | Filter by member      |
| `saving_type` | int  | Filter by saving type |
| `date_from`   | date | From date             |
| `date_to`     | date | To date               |

Returns savings + `total_savings` + `active_savers` count.

#### `GET /api/v1/admin/reports/shares`

| Param           | Type | Description |
| --------------- | ---- | ----------- |
| `share_type_id` | int  | Type        |
| `month_id`      | int  | Month       |
| `year_id`       | int  | Year        |

#### `GET /api/v1/admin/reports/loans`

| Param          | Type   | Description |
| -------------- | ------ | ----------- |
| `loan_type_id` | int    | Type        |
| `status`       | string | Status      |

Returns loans + `total_loans` + `total_repayments` + `outstanding_balance`.

#### `GET /api/v1/admin/reports/transactions`

| Param       | Type   | Description      |
| ----------- | ------ | ---------------- |
| `type`      | string | Transaction type |
| `status`    | string | Status           |
| `date_from` | date   | From date        |
| `date_to`   | date   | To date          |

#### `GET /api/v1/admin/reports/savings-summary`

| Param    | Type   | Description              |
| -------- | ------ | ------------------------ |
| `search` | string | Search by name/member_no |

Returns per-member totals: `total_saved`, `total_withdrawn`, `balance`.

```json
{
    "success": true,
    "data": {
        "members": {
            "data": [
                {
                    "surname": "Doe",
                    "total_saved": 60000,
                    "total_withdrawn": 10000,
                    "balance": 50000
                }
            ]
        },
        "overall": {
            "total_saved": 15000000,
            "total_withdrawn": 2500000,
            "total_balance": 12500000
        }
    }
}
```

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

### Admin Redux Slices (Phase 3)

Below are the Redux slices for all admin modules. Create each file under `src/store/slices/admin/`.

#### 1. `adminDashboardSlice.js`

```javascript
// src/store/slices/admin/adminDashboardSlice.js
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import api from "../../api";

export const fetchAdminDashboard = createAsyncThunk(
    "adminDashboard/fetch",
    async (_, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/dashboard");
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

const adminDashboardSlice = createSlice({
    name: "adminDashboard",
    initialState: {
        stats: null,
        monthlyData: [],
        recentMembers: [],
        recentTransactions: [],
        pendingLoans: [],
        loading: false,
        error: null,
    },
    reducers: {
        clearAdminDashboard: (state) => {
            state.stats = null;
            state.monthlyData = [];
            state.recentMembers = [];
            state.recentTransactions = [];
            state.pendingLoans = [];
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchAdminDashboard.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(fetchAdminDashboard.fulfilled, (state, { payload }) => {
                state.loading = false;
                state.stats = payload;
                state.monthlyData = payload.monthly_data;
                state.recentMembers = payload.recent_members;
                state.recentTransactions = payload.recent_transactions;
                state.pendingLoans = payload.pending_loans;
            })
            .addCase(fetchAdminDashboard.rejected, (state, { payload }) => {
                state.loading = false;
                state.error = payload?.message || "Failed to load dashboard";
            });
    },
});

export const { clearAdminDashboard } = adminDashboardSlice.actions;
export default adminDashboardSlice.reducer;
```

#### 2. `adminMembersSlice.js`

```javascript
// src/store/slices/admin/adminMembersSlice.js
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import api from "../../api";

export const fetchAdminMembers = createAsyncThunk(
    "adminMembers/fetchAll",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/members", { params });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const fetchAdminMember = createAsyncThunk(
    "adminMembers/fetchOne",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.get(`/admin/members/${id}`);
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createAdminMember = createAsyncThunk(
    "adminMembers/create",
    async (formData, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/members", formData);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const updateAdminMember = createAsyncThunk(
    "adminMembers/update",
    async ({ id, ...payload }, { rejectWithValue }) => {
        try {
            const { data } = await api.put(`/admin/members/${id}`, payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const approveMember = createAsyncThunk(
    "adminMembers/approve",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(`/admin/members/${id}/approve`);
            return { id, ...data };
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const rejectMember = createAsyncThunk(
    "adminMembers/reject",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(`/admin/members/${id}/reject`);
            return { id, ...data };
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const suspendMember = createAsyncThunk(
    "adminMembers/suspend",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(`/admin/members/${id}/suspend`);
            return { id, ...data };
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const activateMember = createAsyncThunk(
    "adminMembers/activate",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(`/admin/members/${id}/activate`);
            return { id, ...data };
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const deleteMember = createAsyncThunk(
    "adminMembers/delete",
    async (id, { rejectWithValue }) => {
        try {
            await api.delete(`/admin/members/${id}`);
            return id;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

const adminMembersSlice = createSlice({
    name: "adminMembers",
    initialState: {
        list: [],
        pagination: null,
        selected: null,
        totalMembers: 0,
        approvedThisMonth: 0,
        loading: false,
        actionLoading: false,
        error: null,
        successMessage: null,
    },
    reducers: {
        clearMemberError: (state) => {
            state.error = null;
        },
        clearMemberSuccess: (state) => {
            state.successMessage = null;
        },
    },
    extraReducers: (builder) => {
        builder
            // Fetch all
            .addCase(fetchAdminMembers.pending, (state) => {
                state.loading = true;
                state.error = null;
            })
            .addCase(fetchAdminMembers.fulfilled, (state, { payload }) => {
                state.loading = false;
                state.list = payload.members.data;
                state.pagination = {
                    current_page: payload.members.current_page,
                    last_page: payload.members.last_page,
                    total: payload.members.total,
                };
                state.totalMembers = payload.total_members;
                state.approvedThisMonth = payload.approved_this_month;
            })
            .addCase(fetchAdminMembers.rejected, (state, { payload }) => {
                state.loading = false;
                state.error = payload?.message;
            })
            // Fetch one
            .addCase(fetchAdminMember.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchAdminMember.fulfilled, (state, { payload }) => {
                state.loading = false;
                state.selected = payload;
            })
            .addCase(fetchAdminMember.rejected, (state, { payload }) => {
                state.loading = false;
                state.error = payload?.message;
            })
            // Actions (approve, reject, suspend, activate, delete)
            .addCase(approveMember.pending, (state) => {
                state.actionLoading = true;
            })
            .addCase(approveMember.fulfilled, (state, { payload }) => {
                state.actionLoading = false;
                state.successMessage = payload.message;
                state.list = state.list.map((m) =>
                    m.id === payload.id ? { ...m, admin_sign: "Yes" } : m,
                );
            })
            .addCase(approveMember.rejected, (state, { payload }) => {
                state.actionLoading = false;
                state.error = payload?.message;
            })
            .addCase(rejectMember.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(suspendMember.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(activateMember.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(deleteMember.fulfilled, (state, action) => {
                state.list = state.list.filter((m) => m.id !== action.payload);
                state.successMessage = "Member deleted";
            });
    },
});

export const { clearMemberError, clearMemberSuccess } =
    adminMembersSlice.actions;
export default adminMembersSlice.reducer;
```

#### 3. `adminSavingsSlice.js`

```javascript
// src/store/slices/admin/adminSavingsSlice.js
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import api from "../../api";

// ── Saving Types ──
export const fetchSavingTypes = createAsyncThunk(
    "adminSavings/fetchTypes",
    async (_, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/saving-types");
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createSavingType = createAsyncThunk(
    "adminSavings/createType",
    async (payload, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/saving-types", payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const updateSavingType = createAsyncThunk(
    "adminSavings/updateType",
    async ({ id, ...payload }, { rejectWithValue }) => {
        try {
            const { data } = await api.put(
                `/admin/saving-types/${id}`,
                payload,
            );
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Savings CRUD ──
export const fetchAdminSavings = createAsyncThunk(
    "adminSavings/fetchAll",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/savings", { params });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createSaving = createAsyncThunk(
    "adminSavings/create",
    async (payload, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/savings", payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const updateSaving = createAsyncThunk(
    "adminSavings/update",
    async ({ id, ...payload }, { rejectWithValue }) => {
        try {
            const { data } = await api.put(`/admin/savings/${id}`, payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const deleteSaving = createAsyncThunk(
    "adminSavings/delete",
    async (id, { rejectWithValue }) => {
        try {
            await api.delete(`/admin/savings/${id}`);
            return id;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Savings Settings ──
export const fetchSavingsSettings = createAsyncThunk(
    "adminSavings/fetchSettings",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/savings-settings", {
                params,
            });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const approveSavingsSetting = createAsyncThunk(
    "adminSavings/approveSetting",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(
                `/admin/savings-settings/${id}/approve`,
            );
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const rejectSavingsSetting = createAsyncThunk(
    "adminSavings/rejectSetting",
    async ({ id, admin_notes }, { rejectWithValue }) => {
        try {
            const { data } = await api.post(
                `/admin/savings-settings/${id}/reject`,
                { admin_notes },
            );
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Entrance Fees ──
export const fetchEntranceFees = createAsyncThunk(
    "adminSavings/fetchFees",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/entrance-fees", { params });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createEntranceFee = createAsyncThunk(
    "adminSavings/createFee",
    async (payload, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/entrance-fees", payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

const adminSavingsSlice = createSlice({
    name: "adminSavings",
    initialState: {
        savings: [],
        savingTypes: [],
        entranceFees: [],
        settings: [],
        totalSavings: 0,
        pagination: null,
        months: [],
        years: [],
        loading: false,
        actionLoading: false,
        error: null,
        successMessage: null,
    },
    reducers: {
        clearSavingsError: (state) => {
            state.error = null;
        },
        clearSavingsSuccess: (state) => {
            state.successMessage = null;
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchAdminSavings.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchAdminSavings.fulfilled, (state, { payload }) => {
                state.loading = false;
                state.savings = payload.savings?.data || [];
                state.totalSavings = payload.total_savings;
                state.savingTypes = payload.saving_types || state.savingTypes;
                state.months = payload.months || state.months;
                state.years = payload.years || state.years;
                state.pagination = payload.savings
                    ? {
                          current_page: payload.savings.current_page,
                          last_page: payload.savings.last_page,
                          total: payload.savings.total,
                      }
                    : null;
            })
            .addCase(fetchAdminSavings.rejected, (state, { payload }) => {
                state.loading = false;
                state.error = payload?.message;
            })
            .addCase(fetchSavingTypes.fulfilled, (state, { payload }) => {
                state.savingTypes = payload;
            })
            .addCase(createSaving.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(createSaving.rejected, (state, { payload }) => {
                state.error = payload?.message;
            })
            .addCase(fetchEntranceFees.fulfilled, (state, { payload }) => {
                state.entranceFees = payload.entrance_fees?.data || [];
            })
            .addCase(createEntranceFee.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(fetchSavingsSettings.fulfilled, (state, { payload }) => {
                state.settings = payload?.data || [];
            })
            .addCase(approveSavingsSetting.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(rejectSavingsSetting.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            });
    },
});

export const { clearSavingsError, clearSavingsSuccess } =
    adminSavingsSlice.actions;
export default adminSavingsSlice.reducer;
```

#### 4. `adminSharesSlice.js`

```javascript
// src/store/slices/admin/adminSharesSlice.js
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import api from "../../api";

export const fetchShareTypes = createAsyncThunk(
    "adminShares/fetchTypes",
    async (_, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/share-types");
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createShareType = createAsyncThunk(
    "adminShares/createType",
    async (payload, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/share-types", payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const fetchAdminShares = createAsyncThunk(
    "adminShares/fetchAll",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/shares", { params });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createShare = createAsyncThunk(
    "adminShares/create",
    async (payload, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/shares", payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const approveShare = createAsyncThunk(
    "adminShares/approve",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(`/admin/shares/${id}/approve`);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const rejectShare = createAsyncThunk(
    "adminShares/reject",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(`/admin/shares/${id}/reject`);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

const adminSharesSlice = createSlice({
    name: "adminShares",
    initialState: {
        shares: [],
        shareTypes: [],
        totalShares: 0,
        pagination: null,
        months: [],
        years: [],
        loading: false,
        error: null,
        successMessage: null,
    },
    reducers: {
        clearSharesError: (state) => {
            state.error = null;
        },
        clearSharesSuccess: (state) => {
            state.successMessage = null;
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchAdminShares.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchAdminShares.fulfilled, (state, { payload }) => {
                state.loading = false;
                state.shares = payload.shares?.data || [];
                state.totalShares = payload.total_shares;
                state.shareTypes = payload.share_types || state.shareTypes;
                state.months = payload.months || state.months;
                state.years = payload.years || state.years;
            })
            .addCase(fetchAdminShares.rejected, (state, { payload }) => {
                state.loading = false;
                state.error = payload?.message;
            })
            .addCase(fetchShareTypes.fulfilled, (state, { payload }) => {
                state.shareTypes = payload;
            })
            .addCase(createShare.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(approveShare.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(rejectShare.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            });
    },
});

export const { clearSharesError, clearSharesSuccess } =
    adminSharesSlice.actions;
export default adminSharesSlice.reducer;
```

#### 5. `adminLoansSlice.js`

```javascript
// src/store/slices/admin/adminLoansSlice.js
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import api from "../../api";

// ── Loan Types ──
export const fetchLoanTypes = createAsyncThunk(
    "adminLoans/fetchTypes",
    async (_, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/loan-types");
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createLoanType = createAsyncThunk(
    "adminLoans/createType",
    async (payload, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/loan-types", payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const updateLoanType = createAsyncThunk(
    "adminLoans/updateType",
    async ({ id, ...payload }, { rejectWithValue }) => {
        try {
            const { data } = await api.put(`/admin/loan-types/${id}`, payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Loans CRUD ──
export const fetchAdminLoans = createAsyncThunk(
    "adminLoans/fetchAll",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/loans", { params });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const fetchAdminLoan = createAsyncThunk(
    "adminLoans/fetchOne",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.get(`/admin/loans/${id}`);
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createLoan = createAsyncThunk(
    "adminLoans/create",
    async (payload, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/loans", payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const approveLoan = createAsyncThunk(
    "adminLoans/approve",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(`/admin/loans/${id}/approve`);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const rejectLoan = createAsyncThunk(
    "adminLoans/reject",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(`/admin/loans/${id}/reject`);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Loan Repayments ──
export const fetchLoanRepayments = createAsyncThunk(
    "adminLoans/fetchRepayments",
    async (_, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/loan-repayments");
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createLoanRepayment = createAsyncThunk(
    "adminLoans/createRepayment",
    async ({ loanId, ...payload }, { rejectWithValue }) => {
        try {
            const { data } = await api.post(
                `/admin/loan-repayments/${loanId}`,
                payload,
            );
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

const adminLoansSlice = createSlice({
    name: "adminLoans",
    initialState: {
        loans: [],
        loanTypes: [],
        selected: null,
        repayments: [],
        statusCounts: {},
        totalLoanAmount: 0,
        pagination: null,
        loading: false,
        actionLoading: false,
        error: null,
        successMessage: null,
    },
    reducers: {
        clearLoansError: (state) => {
            state.error = null;
        },
        clearLoansSuccess: (state) => {
            state.successMessage = null;
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchAdminLoans.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchAdminLoans.fulfilled, (state, { payload }) => {
                state.loading = false;
                state.loans = payload.loans?.data || [];
                state.totalLoanAmount = payload.total_loan_amount;
                state.statusCounts = payload.status_counts || {};
            })
            .addCase(fetchAdminLoans.rejected, (state, { payload }) => {
                state.loading = false;
                state.error = payload?.message;
            })
            .addCase(fetchAdminLoan.fulfilled, (state, { payload }) => {
                state.selected = payload;
            })
            .addCase(fetchLoanTypes.fulfilled, (state, { payload }) => {
                state.loanTypes = payload;
            })
            .addCase(createLoan.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(approveLoan.pending, (state) => {
                state.actionLoading = true;
            })
            .addCase(approveLoan.fulfilled, (state, { payload }) => {
                state.actionLoading = false;
                state.successMessage = payload.message;
            })
            .addCase(approveLoan.rejected, (state, { payload }) => {
                state.actionLoading = false;
                state.error = payload?.message;
            })
            .addCase(rejectLoan.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(fetchLoanRepayments.fulfilled, (state, { payload }) => {
                state.repayments = payload;
            })
            .addCase(createLoanRepayment.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            });
    },
});

export const { clearLoansError, clearLoansSuccess } = adminLoansSlice.actions;
export default adminLoansSlice.reducer;
```

#### 6. `adminWithdrawalsSlice.js`

```javascript
// src/store/slices/admin/adminWithdrawalsSlice.js
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import api from "../../api";

export const fetchAdminWithdrawals = createAsyncThunk(
    "adminWithdrawals/fetchAll",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/withdrawals", { params });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createWithdrawal = createAsyncThunk(
    "adminWithdrawals/create",
    async (payload, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/withdrawals", payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const approveWithdrawal = createAsyncThunk(
    "adminWithdrawals/approve",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(`/admin/withdrawals/${id}/approve`);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const rejectWithdrawal = createAsyncThunk(
    "adminWithdrawals/reject",
    async ({ id, rejection_reason }, { rejectWithValue }) => {
        try {
            const { data } = await api.post(`/admin/withdrawals/${id}/reject`, {
                rejection_reason,
            });
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

const adminWithdrawalsSlice = createSlice({
    name: "adminWithdrawals",
    initialState: {
        withdrawals: [],
        totalAmount: 0,
        pendingAmount: 0,
        approvedAmount: 0,
        pagination: null,
        loading: false,
        error: null,
        successMessage: null,
    },
    reducers: {
        clearWithdrawalsError: (state) => {
            state.error = null;
        },
        clearWithdrawalsSuccess: (state) => {
            state.successMessage = null;
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchAdminWithdrawals.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchAdminWithdrawals.fulfilled, (state, { payload }) => {
                state.loading = false;
                state.withdrawals = payload.withdrawals?.data || [];
                state.totalAmount = payload.total_amount;
                state.pendingAmount = payload.pending_amount;
                state.approvedAmount = payload.approved_amount;
            })
            .addCase(fetchAdminWithdrawals.rejected, (state, { payload }) => {
                state.loading = false;
                state.error = payload?.message;
            })
            .addCase(createWithdrawal.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(approveWithdrawal.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(rejectWithdrawal.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            });
    },
});

export const { clearWithdrawalsError, clearWithdrawalsSuccess } =
    adminWithdrawalsSlice.actions;
export default adminWithdrawalsSlice.reducer;
```

#### 7. `adminTransactionsSlice.js`

```javascript
// src/store/slices/admin/adminTransactionsSlice.js
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import api from "../../api";

export const fetchAdminTransactions = createAsyncThunk(
    "adminTransactions/fetchAll",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/transactions", { params });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const fetchAdminTransaction = createAsyncThunk(
    "adminTransactions/fetchOne",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.get(`/admin/transactions/${id}`);
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const deleteTransaction = createAsyncThunk(
    "adminTransactions/delete",
    async (id, { rejectWithValue }) => {
        try {
            await api.delete(`/admin/transactions/${id}`);
            return id;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

const adminTransactionsSlice = createSlice({
    name: "adminTransactions",
    initialState: {
        transactions: [],
        selected: null,
        totalCredits: 0,
        totalDebits: 0,
        pagination: null,
        loading: false,
        error: null,
    },
    reducers: {
        clearTransactionsError: (state) => {
            state.error = null;
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchAdminTransactions.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchAdminTransactions.fulfilled, (state, { payload }) => {
                state.loading = false;
                state.transactions = payload.transactions?.data || [];
                state.totalCredits = payload.total_credits;
                state.totalDebits = payload.total_debits;
            })
            .addCase(fetchAdminTransactions.rejected, (state, { payload }) => {
                state.loading = false;
                state.error = payload?.message;
            })
            .addCase(fetchAdminTransaction.fulfilled, (state, { payload }) => {
                state.selected = payload;
            })
            .addCase(deleteTransaction.fulfilled, (state, action) => {
                state.transactions = state.transactions.filter(
                    (t) => t.id !== action.payload,
                );
            });
    },
});

export const { clearTransactionsError } = adminTransactionsSlice.actions;
export default adminTransactionsSlice.reducer;
```

#### 8. `adminCommoditiesSlice.js`

```javascript
// src/store/slices/admin/adminCommoditiesSlice.js
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import api from "../../api";

// ── Commodities CRUD ──
export const fetchAdminCommodities = createAsyncThunk(
    "adminCommodities/fetchAll",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/commodities", { params });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createCommodity = createAsyncThunk(
    "adminCommodities/create",
    async (formData, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/commodities", formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const updateCommodity = createAsyncThunk(
    "adminCommodities/update",
    async ({ id, formData }, { rejectWithValue }) => {
        try {
            formData.append("_method", "PUT");
            const { data } = await api.post(
                `/admin/commodities/${id}`,
                formData,
                { headers: { "Content-Type": "multipart/form-data" } },
            );
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const deleteCommodity = createAsyncThunk(
    "adminCommodities/delete",
    async (id, { rejectWithValue }) => {
        try {
            await api.delete(`/admin/commodities/${id}`);
            return id;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Subscriptions ──
export const fetchCommoditySubscriptions = createAsyncThunk(
    "adminCommodities/fetchSubs",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/commodity-subscriptions", {
                params,
            });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const approveSubscription = createAsyncThunk(
    "adminCommodities/approveSub",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(
                `/admin/commodity-subscriptions/${id}/approve`,
            );
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const rejectSubscription = createAsyncThunk(
    "adminCommodities/rejectSub",
    async ({ id, admin_notes }, { rejectWithValue }) => {
        try {
            const { data } = await api.post(
                `/admin/commodity-subscriptions/${id}/reject`,
                { admin_notes },
            );
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Commodity Payments ──
export const fetchCommodityPayments = createAsyncThunk(
    "adminCommodities/fetchPayments",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/commodity-payments", {
                params,
            });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const createCommodityPayment = createAsyncThunk(
    "adminCommodities/createPayment",
    async ({ subscriptionId, ...payload }, { rejectWithValue }) => {
        try {
            const { data } = await api.post(
                `/admin/commodity-payments/${subscriptionId}`,
                payload,
            );
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const approveCommodityPayment = createAsyncThunk(
    "adminCommodities/approvePayment",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(
                `/admin/commodity-payments/${id}/approve`,
            );
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

const adminCommoditiesSlice = createSlice({
    name: "adminCommodities",
    initialState: {
        commodities: [],
        subscriptions: [],
        payments: [],
        pagination: null,
        loading: false,
        error: null,
        successMessage: null,
    },
    reducers: {
        clearCommoditiesError: (state) => {
            state.error = null;
        },
        clearCommoditiesSuccess: (state) => {
            state.successMessage = null;
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchAdminCommodities.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchAdminCommodities.fulfilled, (state, { payload }) => {
                state.loading = false;
                state.commodities = payload?.data || [];
            })
            .addCase(fetchAdminCommodities.rejected, (state, { payload }) => {
                state.loading = false;
                state.error = payload?.message;
            })
            .addCase(createCommodity.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(deleteCommodity.fulfilled, (state, action) => {
                state.commodities = state.commodities.filter(
                    (c) => c.id !== action.payload,
                );
            })
            .addCase(
                fetchCommoditySubscriptions.fulfilled,
                (state, { payload }) => {
                    state.subscriptions = payload?.data || [];
                },
            )
            .addCase(approveSubscription.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(rejectSubscription.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(fetchCommodityPayments.fulfilled, (state, { payload }) => {
                state.payments = payload?.data || [];
            })
            .addCase(createCommodityPayment.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(
                approveCommodityPayment.fulfilled,
                (state, { payload }) => {
                    state.successMessage = payload.message;
                },
            );
    },
});

export const { clearCommoditiesError, clearCommoditiesSuccess } =
    adminCommoditiesSlice.actions;
export default adminCommoditiesSlice.reducer;
```

#### 9. `adminSettingsSlice.js`

```javascript
// src/store/slices/admin/adminSettingsSlice.js
// Handles: FAQs, Resources, Profile Update Requests, Admin Users, Roles & Permissions
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import api from "../../api";

// ── FAQs ──
export const fetchFaqs = createAsyncThunk(
    "adminSettings/fetchFaqs",
    async (_, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/faqs");
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);
export const createFaq = createAsyncThunk(
    "adminSettings/createFaq",
    async (payload, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/faqs", payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);
export const updateFaq = createAsyncThunk(
    "adminSettings/updateFaq",
    async ({ id, ...payload }, { rejectWithValue }) => {
        try {
            const { data } = await api.put(`/admin/faqs/${id}`, payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);
export const deleteFaq = createAsyncThunk(
    "adminSettings/deleteFaq",
    async (id, { rejectWithValue }) => {
        try {
            await api.delete(`/admin/faqs/${id}`);
            return id;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Resources ──
export const fetchResources = createAsyncThunk(
    "adminSettings/fetchResources",
    async (_, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/resources");
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);
export const uploadResource = createAsyncThunk(
    "adminSettings/uploadResource",
    async (formData, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/resources", formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);
export const deleteResource = createAsyncThunk(
    "adminSettings/deleteResource",
    async (id, { rejectWithValue }) => {
        try {
            await api.delete(`/admin/resources/${id}`);
            return id;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Profile Update Requests ──
export const fetchProfileRequests = createAsyncThunk(
    "adminSettings/fetchProfileRequests",
    async (_, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/profile-requests");
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);
export const approveProfileRequest = createAsyncThunk(
    "adminSettings/approveProfile",
    async (id, { rejectWithValue }) => {
        try {
            const { data } = await api.post(
                `/admin/profile-requests/${id}/approve`,
            );
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);
export const rejectProfileRequest = createAsyncThunk(
    "adminSettings/rejectProfile",
    async ({ id, reason }, { rejectWithValue }) => {
        try {
            const { data } = await api.post(
                `/admin/profile-requests/${id}/reject`,
                { reason },
            );
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Admin Users ──
export const fetchAdmins = createAsyncThunk(
    "adminSettings/fetchAdmins",
    async (_, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/admins");
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);
export const createAdmin = createAsyncThunk(
    "adminSettings/createAdmin",
    async (payload, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/admins", payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Roles & Permissions ──
export const fetchRoles = createAsyncThunk(
    "adminSettings/fetchRoles",
    async (_, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/roles");
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);
export const createRole = createAsyncThunk(
    "adminSettings/createRole",
    async (payload, { rejectWithValue }) => {
        try {
            const { data } = await api.post("/admin/roles", payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);
export const updateRole = createAsyncThunk(
    "adminSettings/updateRole",
    async ({ id, ...payload }, { rejectWithValue }) => {
        try {
            const { data } = await api.put(`/admin/roles/${id}`, payload);
            return data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);
export const fetchPermissions = createAsyncThunk(
    "adminSettings/fetchPermissions",
    async (_, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/permissions");
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

const adminSettingsSlice = createSlice({
    name: "adminSettings",
    initialState: {
        faqs: [],
        resources: [],
        profileRequests: [],
        admins: [],
        roles: [],
        permissions: [],
        loading: false,
        error: null,
        successMessage: null,
    },
    reducers: {
        clearSettingsError: (state) => {
            state.error = null;
        },
        clearSettingsSuccess: (state) => {
            state.successMessage = null;
        },
    },
    extraReducers: (builder) => {
        builder
            // FAQs
            .addCase(fetchFaqs.fulfilled, (state, { payload }) => {
                state.faqs = payload;
            })
            .addCase(createFaq.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(updateFaq.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(deleteFaq.fulfilled, (state, action) => {
                state.faqs = state.faqs.filter((f) => f.id !== action.payload);
            })
            // Resources
            .addCase(fetchResources.fulfilled, (state, { payload }) => {
                state.resources = payload?.data || [];
            })
            .addCase(uploadResource.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(deleteResource.fulfilled, (state, action) => {
                state.resources = state.resources.filter(
                    (r) => r.id !== action.payload,
                );
            })
            // Profile Requests
            .addCase(fetchProfileRequests.fulfilled, (state, { payload }) => {
                state.profileRequests = payload?.data || [];
            })
            .addCase(approveProfileRequest.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(rejectProfileRequest.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            // Admins
            .addCase(fetchAdmins.fulfilled, (state, { payload }) => {
                state.admins = payload;
            })
            .addCase(createAdmin.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            // Roles & Permissions
            .addCase(fetchRoles.fulfilled, (state, { payload }) => {
                state.roles = payload?.data || [];
            })
            .addCase(createRole.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(updateRole.fulfilled, (state, { payload }) => {
                state.successMessage = payload.message;
            })
            .addCase(fetchPermissions.fulfilled, (state, { payload }) => {
                state.permissions = payload;
            });
    },
});

export const { clearSettingsError, clearSettingsSuccess } =
    adminSettingsSlice.actions;
export default adminSettingsSlice.reducer;
```

#### 10. `adminReportsSlice.js`

```javascript
// src/store/slices/admin/adminReportsSlice.js
// Handles: Financial Summary + Reports
import { createSlice, createAsyncThunk } from "@reduxjs/toolkit";
import api from "../../api";

// ── Financial Summary ──
export const fetchFinancialSummary = createAsyncThunk(
    "adminReports/financialSummary",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/financial-summary", {
                params,
            });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const fetchMemberFinancialSummary = createAsyncThunk(
    "adminReports/memberFinancial",
    async ({ memberId, ...params }, { rejectWithValue }) => {
        try {
            const { data } = await api.get(
                `/admin/financial-summary/${memberId}`,
                { params },
            );
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

// ── Reports ──
export const fetchMembersReport = createAsyncThunk(
    "adminReports/members",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/reports/members", {
                params,
            });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const fetchSavingsReport = createAsyncThunk(
    "adminReports/savings",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/reports/savings", {
                params,
            });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const fetchSharesReport = createAsyncThunk(
    "adminReports/shares",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/reports/shares", { params });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const fetchLoansReport = createAsyncThunk(
    "adminReports/loans",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/reports/loans", { params });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const fetchTransactionsReport = createAsyncThunk(
    "adminReports/transactions",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/reports/transactions", {
                params,
            });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

export const fetchSavingsSummaryReport = createAsyncThunk(
    "adminReports/savingsSummary",
    async (params = {}, { rejectWithValue }) => {
        try {
            const { data } = await api.get("/admin/reports/savings-summary", {
                params,
            });
            return data.data;
        } catch (err) {
            return rejectWithValue(
                err.response?.data || { message: err.message },
            );
        }
    },
);

const adminReportsSlice = createSlice({
    name: "adminReports",
    initialState: {
        financialSummary: null,
        memberSummary: null,
        reportData: null,
        loading: false,
        error: null,
    },
    reducers: {
        clearReportsError: (state) => {
            state.error = null;
        },
        clearReportData: (state) => {
            state.reportData = null;
        },
    },
    extraReducers: (builder) => {
        builder
            .addCase(fetchFinancialSummary.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchFinancialSummary.fulfilled, (state, { payload }) => {
                state.loading = false;
                state.financialSummary = payload;
            })
            .addCase(fetchFinancialSummary.rejected, (state, { payload }) => {
                state.loading = false;
                state.error = payload?.message;
            })
            .addCase(
                fetchMemberFinancialSummary.fulfilled,
                (state, { payload }) => {
                    state.memberSummary = payload;
                },
            )
            // All reports go into reportData (generic)
            .addCase(fetchMembersReport.pending, (state) => {
                state.loading = true;
            })
            .addCase(fetchMembersReport.fulfilled, (state, { payload }) => {
                state.loading = false;
                state.reportData = payload;
            })
            .addCase(fetchMembersReport.rejected, (state, { payload }) => {
                state.loading = false;
                state.error = payload?.message;
            })
            .addCase(fetchSavingsReport.fulfilled, (state, { payload }) => {
                state.reportData = payload;
            })
            .addCase(fetchSharesReport.fulfilled, (state, { payload }) => {
                state.reportData = payload;
            })
            .addCase(fetchLoansReport.fulfilled, (state, { payload }) => {
                state.reportData = payload;
            })
            .addCase(
                fetchTransactionsReport.fulfilled,
                (state, { payload }) => {
                    state.reportData = payload;
                },
            )
            .addCase(
                fetchSavingsSummaryReport.fulfilled,
                (state, { payload }) => {
                    state.reportData = payload;
                },
            );
    },
});

export const { clearReportsError, clearReportData } = adminReportsSlice.actions;
export default adminReportsSlice.reducer;
```

#### Updated `src/store/index.js` (add all admin slices)

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

// ── Auth & Lookup (Phase 1) ──
import authReducer from "./slices/authSlice";
import lookupReducer from "./slices/lookupSlice";

// ── Member slices (Phase 2) ──
import dashboardReducer from "./slices/dashboardSlice";
import savingsReducer from "./slices/savingsSlice";
import sharesReducer from "./slices/sharesSlice";
import loansReducer from "./slices/loansSlice";
import withdrawalsReducer from "./slices/withdrawalsSlice";
import transactionsReducer from "./slices/transactionsSlice";
import commoditiesReducer from "./slices/commoditiesSlice";
import notificationsReducer from "./slices/notificationsSlice";
import financialReducer from "./slices/financialSlice";

// ── Admin slices (Phase 3) ──
import adminDashboardReducer from "./slices/admin/adminDashboardSlice";
import adminMembersReducer from "./slices/admin/adminMembersSlice";
import adminSavingsReducer from "./slices/admin/adminSavingsSlice";
import adminSharesReducer from "./slices/admin/adminSharesSlice";
import adminLoansReducer from "./slices/admin/adminLoansSlice";
import adminWithdrawalsReducer from "./slices/admin/adminWithdrawalsSlice";
import adminTransactionsReducer from "./slices/admin/adminTransactionsSlice";
import adminCommoditiesReducer from "./slices/admin/adminCommoditiesSlice";
import adminSettingsReducer from "./slices/admin/adminSettingsSlice";
import adminReportsReducer from "./slices/admin/adminReportsSlice";

const persistConfig = {
    key: "root",
    storage: AsyncStorage,
    whitelist: ["auth"], // only persist auth (token, user, role)
};

const rootReducer = combineReducers({
    // Auth & Lookup
    auth: authReducer,
    lookup: lookupReducer,
    // Member modules
    dashboard: dashboardReducer,
    savings: savingsReducer,
    shares: sharesReducer,
    loans: loansReducer,
    withdrawals: withdrawalsReducer,
    transactions: transactionsReducer,
    commodities: commoditiesReducer,
    notifications: notificationsReducer,
    financial: financialReducer,
    // Admin modules
    adminDashboard: adminDashboardReducer,
    adminMembers: adminMembersReducer,
    adminSavings: adminSavingsReducer,
    adminShares: adminSharesReducer,
    adminLoans: adminLoansReducer,
    adminWithdrawals: adminWithdrawalsReducer,
    adminTransactions: adminTransactionsReducer,
    adminCommodities: adminCommoditiesReducer,
    adminSettings: adminSettingsReducer,
    adminReports: adminReportsReducer,
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

> **Interview note:** Admin slices are namespaced under `src/store/slices/admin/` and prefixed with `admin` in the store. This keeps member and admin state cleanly separated. Only `auth` is persisted – all admin data refreshes on each screen mount.

### Admin Screen Implementation Pattern

```javascript
// Example: src/screens/admin/AdminMembersScreen.js
import React, { useEffect, useState, useCallback } from "react";
import {
    View,
    Text,
    FlatList,
    TextInput,
    TouchableOpacity,
    RefreshControl,
    Alert,
    StyleSheet,
} from "react-native";
import { useDispatch, useSelector } from "react-redux";
import {
    fetchAdminMembers,
    approveMember,
    rejectMember,
    suspendMember,
    clearMemberError,
    clearMemberSuccess,
} from "../../store/slices/admin/adminMembersSlice";

export default function AdminMembersScreen({ navigation }) {
    const dispatch = useDispatch();
    const {
        list,
        pagination,
        totalMembers,
        loading,
        actionLoading,
        error,
        successMessage,
    } = useSelector((state) => state.adminMembers);

    const [search, setSearch] = useState("");
    const [refreshing, setRefreshing] = useState(false);

    useEffect(() => {
        dispatch(fetchAdminMembers({ search }));
    }, [dispatch, search]);

    // Show success/error alerts
    useEffect(() => {
        if (successMessage) {
            Alert.alert("Success", successMessage);
            dispatch(clearMemberSuccess());
            dispatch(fetchAdminMembers({ search })); // refresh list
        }
        if (error) {
            Alert.alert("Error", error);
            dispatch(clearMemberError());
        }
    }, [successMessage, error]);

    const onRefresh = useCallback(() => {
        setRefreshing(true);
        dispatch(fetchAdminMembers({ search })).finally(() =>
            setRefreshing(false),
        );
    }, [dispatch, search]);

    const handleApprove = (id) => {
        Alert.alert("Approve Member", "Are you sure?", [
            { text: "Cancel", style: "cancel" },
            { text: "Approve", onPress: () => dispatch(approveMember(id)) },
        ]);
    };

    const handleReject = (id) => {
        Alert.alert("Reject Member", "Are you sure?", [
            { text: "Cancel", style: "cancel" },
            {
                text: "Reject",
                style: "destructive",
                onPress: () => dispatch(rejectMember(id)),
            },
        ]);
    };

    const renderMember = ({ item }) => (
        <TouchableOpacity
            style={styles.card}
            onPress={() =>
                navigation.navigate("AdminMemberDetail", { id: item.id })
            }
        >
            <Text style={styles.name}>
                {item.surname} {item.firstname}
            </Text>
            <Text style={styles.meta}>
                {item.member_no} · {item.email}
            </Text>
            <View style={styles.actions}>
                {item.admin_sign !== "Yes" && (
                    <>
                        <TouchableOpacity
                            style={[styles.btn, styles.btnApprove]}
                            onPress={() => handleApprove(item.id)}
                        >
                            <Text style={styles.btnText}>Approve</Text>
                        </TouchableOpacity>
                        <TouchableOpacity
                            style={[styles.btn, styles.btnReject]}
                            onPress={() => handleReject(item.id)}
                        >
                            <Text style={styles.btnText}>Reject</Text>
                        </TouchableOpacity>
                    </>
                )}
            </View>
        </TouchableOpacity>
    );

    return (
        <View style={styles.container}>
            <TextInput
                style={styles.searchInput}
                placeholder="Search members..."
                value={search}
                onChangeText={setSearch}
            />
            <Text style={styles.count}>Total: {totalMembers}</Text>
            <FlatList
                data={list}
                keyExtractor={(item) => String(item.id)}
                renderItem={renderMember}
                refreshControl={
                    <RefreshControl
                        refreshing={refreshing}
                        onRefresh={onRefresh}
                    />
                }
                ListEmptyComponent={
                    !loading && (
                        <Text style={styles.empty}>No members found</Text>
                    )
                }
            />
        </View>
    );
}

const styles = StyleSheet.create({
    container: { flex: 1, padding: 16, backgroundColor: "#fff" },
    searchInput: {
        borderWidth: 1,
        borderColor: "#ddd",
        borderRadius: 8,
        paddingHorizontal: 12,
        paddingVertical: 8,
        marginBottom: 12,
    },
    count: { fontSize: 14, color: "#666", marginBottom: 8 },
    card: {
        padding: 16,
        borderBottomWidth: 1,
        borderBottomColor: "#eee",
    },
    name: { fontSize: 16, fontWeight: "600" },
    meta: { fontSize: 13, color: "#888", marginTop: 2 },
    actions: { flexDirection: "row", marginTop: 8, gap: 8 },
    btn: { paddingHorizontal: 14, paddingVertical: 6, borderRadius: 6 },
    btnApprove: { backgroundColor: "#22c55e" },
    btnReject: { backgroundColor: "#ef4444" },
    btnText: { color: "#fff", fontWeight: "600", fontSize: 13 },
    empty: { textAlign: "center", color: "#999", marginTop: 40 },
});
```

> **Key Pattern:** Every admin screen follows dispatch-on-mount → show loading → render list → action buttons dispatch thunks → success/error alerts auto-refresh. Reuse `clearError` / `clearSuccess` reducers to prevent stale toasts.

### Admin Endpoint → Screen → Slice Mapping (complete reference)

| API Endpoint                                       | Screen File                          | Slice                    | Thunk                         |
| -------------------------------------------------- | ------------------------------------ | ------------------------ | ----------------------------- |
| `GET /admin/dashboard`                             | `AdminDashboardScreen.js`            | `adminDashboardSlice`    | `fetchAdminDashboard`         |
| `GET /admin/lookup/months`                         | _(used by forms)_                    | _(inline)_               | _(simple fetch)_              |
| `GET /admin/lookup/years`                          | _(used by forms)_                    | _(inline)_               | _(simple fetch)_              |
| `GET /admin/lookup/members?search=`                | _(member picker component)_          | _(inline)_               | _(simple fetch)_              |
| `GET /admin/members`                               | `AdminMembersScreen.js`              | `adminMembersSlice`      | `fetchAdminMembers`           |
| `GET /admin/members/{id}`                          | `AdminMemberDetailScreen.js`         | `adminMembersSlice`      | `fetchAdminMember`            |
| `POST /admin/members`                              | `AdminCreateMemberScreen.js`         | `adminMembersSlice`      | `createAdminMember`           |
| `PUT /admin/members/{id}`                          | `AdminEditMemberScreen.js`           | `adminMembersSlice`      | `updateAdminMember`           |
| `POST /admin/members/{id}/approve`                 | `AdminMembersScreen.js`              | `adminMembersSlice`      | `approveMember`               |
| `POST /admin/members/{id}/reject`                  | `AdminMembersScreen.js`              | `adminMembersSlice`      | `rejectMember`                |
| `POST /admin/members/{id}/suspend`                 | `AdminMemberDetailScreen.js`         | `adminMembersSlice`      | `suspendMember`               |
| `POST /admin/members/{id}/activate`                | `AdminMemberDetailScreen.js`         | `adminMembersSlice`      | `activateMember`              |
| `DELETE /admin/members/{id}`                       | `AdminMemberDetailScreen.js`         | `adminMembersSlice`      | `deleteMember`                |
| `GET /admin/entrance-fees`                         | `AdminEntranceFeesScreen.js`         | `adminSavingsSlice`      | `fetchEntranceFees`           |
| `POST /admin/entrance-fees`                        | `AdminCreateEntranceFeeScreen.js`    | `adminSavingsSlice`      | `createEntranceFee`           |
| `GET /admin/saving-types`                          | `AdminSavingTypesScreen.js`          | `adminSavingsSlice`      | `fetchSavingTypes`            |
| `POST /admin/saving-types`                         | `AdminCreateSavingTypeScreen.js`     | `adminSavingsSlice`      | `createSavingType`            |
| `PUT /admin/saving-types/{id}`                     | `AdminEditSavingTypeScreen.js`       | `adminSavingsSlice`      | `updateSavingType`            |
| `GET /admin/savings`                               | `AdminSavingsScreen.js`              | `adminSavingsSlice`      | `fetchAdminSavings`           |
| `POST /admin/savings`                              | `AdminCreateSavingScreen.js`         | `adminSavingsSlice`      | `createSaving`                |
| `PUT /admin/savings/{id}`                          | `AdminEditSavingScreen.js`           | `adminSavingsSlice`      | `updateSaving`                |
| `DELETE /admin/savings/{id}`                       | `AdminSavingsScreen.js`              | `adminSavingsSlice`      | `deleteSaving`                |
| `GET /admin/savings-settings`                      | `AdminSavingsSettingsScreen.js`      | `adminSavingsSlice`      | `fetchSavingsSettings`        |
| `POST /admin/savings-settings/{id}/approve`        | `AdminSavingsSettingsScreen.js`      | `adminSavingsSlice`      | `approveSavingsSetting`       |
| `POST /admin/savings-settings/{id}/reject`         | `AdminSavingsSettingsScreen.js`      | `adminSavingsSlice`      | `rejectSavingsSetting`        |
| `GET /admin/share-types`                           | `AdminShareTypesScreen.js`           | `adminSharesSlice`       | `fetchShareTypes`             |
| `POST /admin/share-types`                          | `AdminCreateShareTypeScreen.js`      | `adminSharesSlice`       | `createShareType`             |
| `GET /admin/shares`                                | `AdminSharesScreen.js`               | `adminSharesSlice`       | `fetchAdminShares`            |
| `POST /admin/shares`                               | `AdminCreateShareScreen.js`          | `adminSharesSlice`       | `createShare`                 |
| `POST /admin/shares/{id}/approve`                  | `AdminSharesScreen.js`               | `adminSharesSlice`       | `approveShare`                |
| `POST /admin/shares/{id}/reject`                   | `AdminSharesScreen.js`               | `adminSharesSlice`       | `rejectShare`                 |
| `GET /admin/loan-types`                            | `AdminLoanTypesScreen.js`            | `adminLoansSlice`        | `fetchLoanTypes`              |
| `POST /admin/loan-types`                           | `AdminCreateLoanTypeScreen.js`       | `adminLoansSlice`        | `createLoanType`              |
| `PUT /admin/loan-types/{id}`                       | `AdminEditLoanTypeScreen.js`         | `adminLoansSlice`        | `updateLoanType`              |
| `GET /admin/loans`                                 | `AdminLoansScreen.js`                | `adminLoansSlice`        | `fetchAdminLoans`             |
| `GET /admin/loans/{id}`                            | `AdminLoanDetailScreen.js`           | `adminLoansSlice`        | `fetchAdminLoan`              |
| `POST /admin/loans`                                | `AdminCreateLoanScreen.js`           | `adminLoansSlice`        | `createLoan`                  |
| `POST /admin/loans/{id}/approve`                   | `AdminLoanDetailScreen.js`           | `adminLoansSlice`        | `approveLoan`                 |
| `POST /admin/loans/{id}/reject`                    | `AdminLoanDetailScreen.js`           | `adminLoansSlice`        | `rejectLoan`                  |
| `GET /admin/loan-repayments`                       | `AdminLoanRepaymentsScreen.js`       | `adminLoansSlice`        | `fetchLoanRepayments`         |
| `POST /admin/loan-repayments/{loanId}`             | `AdminCreateRepaymentScreen.js`      | `adminLoansSlice`        | `createLoanRepayment`         |
| `GET /admin/withdrawals`                           | `AdminWithdrawalsScreen.js`          | `adminWithdrawalsSlice`  | `fetchAdminWithdrawals`       |
| `POST /admin/withdrawals`                          | `AdminCreateWithdrawalScreen.js`     | `adminWithdrawalsSlice`  | `createWithdrawal`            |
| `POST /admin/withdrawals/{id}/approve`             | `AdminWithdrawalsScreen.js`          | `adminWithdrawalsSlice`  | `approveWithdrawal`           |
| `POST /admin/withdrawals/{id}/reject`              | `AdminWithdrawalsScreen.js`          | `adminWithdrawalsSlice`  | `rejectWithdrawal`            |
| `GET /admin/transactions`                          | `AdminTransactionsScreen.js`         | `adminTransactionsSlice` | `fetchAdminTransactions`      |
| `GET /admin/transactions/{id}`                     | `AdminTransactionDetailScreen.js`    | `adminTransactionsSlice` | `fetchAdminTransaction`       |
| `DELETE /admin/transactions/{id}`                  | `AdminTransactionsScreen.js`         | `adminTransactionsSlice` | `deleteTransaction`           |
| `GET /admin/commodities`                           | `AdminCommoditiesScreen.js`          | `adminCommoditiesSlice`  | `fetchAdminCommodities`       |
| `POST /admin/commodities`                          | `AdminCreateCommodityScreen.js`      | `adminCommoditiesSlice`  | `createCommodity`             |
| `PUT /admin/commodities/{id}`                      | `AdminEditCommodityScreen.js`        | `adminCommoditiesSlice`  | `updateCommodity`             |
| `DELETE /admin/commodities/{id}`                   | `AdminCommoditiesScreen.js`          | `adminCommoditiesSlice`  | `deleteCommodity`             |
| `GET /admin/commodity-subscriptions`               | `AdminSubscriptionsScreen.js`        | `adminCommoditiesSlice`  | `fetchCommoditySubscriptions` |
| `POST /admin/commodity-subscriptions/{id}/approve` | `AdminSubscriptionsScreen.js`        | `adminCommoditiesSlice`  | `approveSubscription`         |
| `POST /admin/commodity-subscriptions/{id}/reject`  | `AdminSubscriptionsScreen.js`        | `adminCommoditiesSlice`  | `rejectSubscription`          |
| `GET /admin/commodity-payments`                    | `AdminCommodityPaymentsScreen.js`    | `adminCommoditiesSlice`  | `fetchCommodityPayments`      |
| `POST /admin/commodity-payments/{subId}`           | `AdminRecordPaymentScreen.js`        | `adminCommoditiesSlice`  | `createCommodityPayment`      |
| `POST /admin/commodity-payments/{id}/approve`      | `AdminCommodityPaymentsScreen.js`    | `adminCommoditiesSlice`  | `approveCommodityPayment`     |
| `GET /admin/profile-requests`                      | `AdminProfileRequestsScreen.js`      | `adminSettingsSlice`     | `fetchProfileRequests`        |
| `POST /admin/profile-requests/{id}/approve`        | `AdminProfileRequestsScreen.js`      | `adminSettingsSlice`     | `approveProfileRequest`       |
| `POST /admin/profile-requests/{id}/reject`         | `AdminProfileRequestsScreen.js`      | `adminSettingsSlice`     | `rejectProfileRequest`        |
| `GET /admin/resources`                             | `AdminResourcesScreen.js`            | `adminSettingsSlice`     | `fetchResources`              |
| `POST /admin/resources`                            | `AdminUploadResourceScreen.js`       | `adminSettingsSlice`     | `uploadResource`              |
| `DELETE /admin/resources/{id}`                     | `AdminResourcesScreen.js`            | `adminSettingsSlice`     | `deleteResource`              |
| `GET /admin/faqs`                                  | `AdminFaqsScreen.js`                 | `adminSettingsSlice`     | `fetchFaqs`                   |
| `POST /admin/faqs`                                 | `AdminCreateFaqScreen.js`            | `adminSettingsSlice`     | `createFaq`                   |
| `PUT /admin/faqs/{id}`                             | `AdminEditFaqScreen.js`              | `adminSettingsSlice`     | `updateFaq`                   |
| `DELETE /admin/faqs/{id}`                          | `AdminFaqsScreen.js`                 | `adminSettingsSlice`     | `deleteFaq`                   |
| `GET /admin/admins`                                | `AdminUsersScreen.js`                | `adminSettingsSlice`     | `fetchAdmins`                 |
| `POST /admin/admins`                               | `AdminCreateAdminScreen.js`          | `adminSettingsSlice`     | `createAdmin`                 |
| `GET /admin/roles`                                 | `AdminRolesScreen.js`                | `adminSettingsSlice`     | `fetchRoles`                  |
| `POST /admin/roles`                                | `AdminCreateRoleScreen.js`           | `adminSettingsSlice`     | `createRole`                  |
| `PUT /admin/roles/{id}`                            | `AdminEditRoleScreen.js`             | `adminSettingsSlice`     | `updateRole`                  |
| `GET /admin/permissions`                           | `AdminRolesScreen.js`                | `adminSettingsSlice`     | `fetchPermissions`            |
| `GET /admin/financial-summary`                     | `AdminFinancialSummaryScreen.js`     | `adminReportsSlice`      | `fetchFinancialSummary`       |
| `GET /admin/financial-summary/{memberId}`          | `AdminMemberFinancialScreen.js`      | `adminReportsSlice`      | `fetchMemberFinancialSummary` |
| `GET /admin/reports/members`                       | `AdminReportMembersScreen.js`        | `adminReportsSlice`      | `fetchMembersReport`          |
| `GET /admin/reports/savings`                       | `AdminReportSavingsScreen.js`        | `adminReportsSlice`      | `fetchSavingsReport`          |
| `GET /admin/reports/shares`                        | `AdminReportSharesScreen.js`         | `adminReportsSlice`      | `fetchSharesReport`           |
| `GET /admin/reports/loans`                         | `AdminReportLoansScreen.js`          | `adminReportsSlice`      | `fetchLoansReport`            |
| `GET /admin/reports/transactions`                  | `AdminReportTransactionsScreen.js`   | `adminReportsSlice`      | `fetchTransactionsReport`     |
| `GET /admin/reports/savings-summary`               | `AdminReportSavingsSummaryScreen.js` | `adminReportsSlice`      | `fetchSavingsSummaryReport`   |

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

| File                                               | Action                     | Purpose                                                         |
| -------------------------------------------------- | -------------------------- | --------------------------------------------------------------- |
| `app/Http/Controllers/Api/V1/AuthController.php`   | **Created** (Phase 1)      | API auth: login, register, logout, profile, password, lookup    |
| `app/Http/Controllers/Api/V1/MemberController.php` | **Created** (Phase 2)      | All 35 member API endpoints (dashboard, savings, loans, etc.)   |
| `app/Http/Controllers/Api/V1/AdminController.php`  | **Created** (Phase 3)      | All 92 admin API endpoints (CRUD, approvals, reports, settings) |
| `routes/api.php`                                   | **Modified**               | API v1 routes (public + 35 member + 92 admin = 127+ total)      |
| `bootstrap/app.php`                                | **Modified**               | Registered `api.php` routing + `ability` middleware alias       |
| `config/auth.php`                                  | **Modified**               | Added `sanctum` guard                                           |
| `app/Models/User.php`                              | **Modified**               | Added `HasApiTokens` trait                                      |
| `composer.json`                                    | **Modified** (by composer) | Added `laravel/sanctum` dependency                              |
| `docs/API.md`                                      | **Created**                | This documentation                                              |

---

## What's Next?

1. ~~**Phase 1 – Auth Endpoints:** Login, register, logout, profile, password reset~~ ✅ Done (11 routes)
2. ~~**Phase 2 – Member Endpoints:** Dashboard, savings, shares, loans, passbook, withdrawals, commodities, guarantor, notifications, financial summary, profile, resources~~ ✅ Done (35 routes)
3. ~~**Phase 3 – Admin Endpoints:** Dashboard, members CRUD, savings/shares/loans CRUD + approvals, withdrawals, transactions, commodities + subscriptions + payments, entrance fees, profile requests, resources, FAQs, admin users, roles & permissions, financial summary, reports~~ ✅ Done (92 routes)
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

Let us know when you're ready to proceed with Phase 4!
