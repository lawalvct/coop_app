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
4. [API Endpoints](#api-endpoints)
    - [Auth – Public](#auth--public)
    - [Auth – Protected](#auth--protected)
    - [Lookup Data](#lookup-data)
5. [Error Handling](#error-handling)
6. [Available Modules (Upcoming Endpoints)](#available-modules-upcoming-endpoints)
7. [React Native Integration Guide (Redux)](#react-native-integration-guide-redux)
8. [Redux Cheat Sheet for Interviews](#redux-cheat-sheet-for-interviews)

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

const persistConfig = {
    key: "root",
    storage: AsyncStorage,
    whitelist: ["auth"], // only persist auth (token, user, role)
};

const rootReducer = combineReducers({
    auth: authReducer,
    lookup: lookupReducer,
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

## Available Modules (Upcoming Endpoints)

Based on the existing web application, the following modules will be API-ified in subsequent releases. The route structure is already prepared in `routes/api.php`.

### Member Endpoints (`/api/v1/member/...`)

| Module                | Planned Endpoints                         | Description                                                               |
| --------------------- | ----------------------------------------- | ------------------------------------------------------------------------- |
| **Dashboard**         | `GET /member/dashboard`                   | Summary stats: savings balance, shares, active loans, recent transactions |
| **Savings**           | `GET /member/savings`                     | List member's savings records                                             |
|                       | `GET /member/savings/monthly-summary`     | Monthly savings breakdown                                                 |
|                       | `GET /member/savings/history`             | Full savings history                                                      |
| **Savings Settings**  | `GET /member/savings-settings`            | View auto-deduction settings                                              |
|                       | `POST /member/savings-settings`           | Update savings preferences                                                |
| **Withdrawals**       | `GET /member/withdrawals`                 | List withdrawal requests                                                  |
|                       | `POST /member/withdrawals`                | Request a new withdrawal                                                  |
| **Shares**            | `GET /member/shares`                      | List share holdings                                                       |
|                       | `POST /member/shares`                     | Purchase new shares                                                       |
| **Loans**             | `GET /member/loans`                       | List loans                                                                |
|                       | `POST /member/loans`                      | Apply for a loan                                                          |
|                       | `GET /member/loans/{id}`                  | Loan details + repayment schedule                                         |
| **Passbook**          | `GET /member/transactions`                | Transaction list (searchable, filterable)                                 |
|                       | `GET /member/transactions/{id}`           | Transaction details                                                       |
| **Commodities**       | `GET /member/commodities`                 | Browse available commodities                                              |
|                       | `POST /member/commodities/{id}/subscribe` | Subscribe to commodity                                                    |
|                       | `GET /member/commodity-subscriptions`     | My subscriptions                                                          |
| **Guarantor**         | `GET /member/guarantor-requests`          | Pending guarantor requests                                                |
|                       | `POST /member/guarantor/{loanId}/respond` | Accept/reject guarantor request                                           |
| **Notifications**     | `GET /member/notifications`               | List notifications                                                        |
|                       | `POST /member/notifications/mark-read`    | Mark all as read                                                          |
| **Profile**           | `PUT /member/profile`                     | Request profile update                                                    |
| **Documents**         | `GET /member/documents/pdf`               | Download membership PDF                                                   |
| **Financial Summary** | `GET /member/financial-summary`           | Overall financial overview                                                |

### Admin Endpoints (`/api/v1/admin/...`)

| Module                      | Planned Endpoints                        | Description                                                   |
| --------------------------- | ---------------------------------------- | ------------------------------------------------------------- |
| **Dashboard**               | `GET /admin/dashboard`                   | System-wide stats                                             |
| **Members**                 | `GET /admin/members`                     | List all members (paginated, searchable)                      |
|                             | `GET /admin/members/{id}`                | Member details                                                |
|                             | `PUT /admin/members/{id}`                | Update member                                                 |
|                             | `POST /admin/members/{id}/approve`       | Approve membership                                            |
| **Entrance Fees**           | `GET /admin/entrance-fees`               | List entrance fee records                                     |
|                             | `POST /admin/entrance-fees`              | Record entrance fee                                           |
|                             | `POST /admin/entrance-fees/import`       | Bulk import (Excel)                                           |
| **Savings Management**      | `GET /admin/savings`                     | All savings records                                           |
|                             | `POST /admin/savings`                    | Record a saving                                               |
|                             | `POST /admin/savings/import`             | Bulk import                                                   |
| **Saving Types**            | `CRUD /admin/saving-types`               | Manage saving categories                                      |
| **Shares**                  | `CRUD /admin/shares`                     | Manage share transactions                                     |
| **Share Types**             | `CRUD /admin/share-types`                | Manage share categories                                       |
| **Loans**                   | `GET /admin/loans`                       | All loan applications                                         |
|                             | `POST /admin/loans/{id}/approve`         | Approve loan                                                  |
|                             | `POST /admin/loans/{id}/reject`          | Reject loan                                                   |
| **Loan Types**              | `CRUD /admin/loan-types`                 | Manage loan categories                                        |
| **Loan Repayments**         | `GET /admin/loan-repayments`             | Repayment records                                             |
|                             | `POST /admin/loan-repayments`            | Record repayment                                              |
| **Transactions**            | `GET /admin/transactions`                | All transactions                                              |
| **Withdrawals**             | `GET /admin/withdrawals`                 | Process withdrawal requests                                   |
|                             | `POST /admin/withdrawals/{id}/approve`   | Approve withdrawal                                            |
| **Commodities**             | `CRUD /admin/commodities`                | Manage commodities                                            |
| **Commodity Subscriptions** | `GET /admin/commodity-subscriptions`     | All subscriptions                                             |
| **Reports**                 | `GET /admin/reports`                     | Generate reports                                              |
|                             | `GET /admin/reports/transaction-summary` | Transaction summary report                                    |
| **Financial Summary**       | `GET /admin/financial-summary`           | Overall cooperative finances                                  |
| **FAQs**                    | `CRUD /admin/faqs`                       | Manage FAQs                                                   |
| **Profile Updates**         | `GET /admin/profile-updates`             | Review member profile change requests                         |
| **Admin Users**             | `CRUD /admin/admins`                     | Manage admin accounts (requires `view_users` permission)      |
| **Roles**                   | `CRUD /admin/roles`                      | Manage roles & permissions (requires `view_roles` permission) |

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
    │       ├── savingsSlice.js    # (Phase 2)
    │       ├── loansSlice.js      # (Phase 2)
    │       ├── sharesSlice.js     # (Phase 2)
    │       └── notificationsSlice.js # (Phase 2)
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
    │   │   ├── SharesScreen.js
    │   │   ├── LoansScreen.js
    │   │   ├── PassbookScreen.js
    │   │   ├── WithdrawalsScreen.js
    │   │   ├── CommoditiesScreen.js
    │   │   ├── NotificationsScreen.js
    │   │   └── ProfileScreen.js
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

| File                                             | Action                     | Purpose                                                      |
| ------------------------------------------------ | -------------------------- | ------------------------------------------------------------ |
| `app/Http/Controllers/Api/V1/AuthController.php` | **Created**                | API auth: login, register, logout, profile, password, lookup |
| `routes/api.php`                                 | **Modified**               | API v1 routes (public + protected)                           |
| `bootstrap/app.php`                              | **Modified**               | Registered `api.php` routing + `ability` middleware alias    |
| `config/auth.php`                                | **Modified**               | Added `sanctum` guard                                        |
| `app/Models/User.php`                            | **Modified**               | Added `HasApiTokens` trait                                   |
| `composer.json`                                  | **Modified** (by composer) | Added `laravel/sanctum` dependency                           |
| `docs/API.md`                                    | **Created**                | This documentation                                           |

---

## What's Next?

1. **Phase 2 – Member Endpoints:** Dashboard stats, savings CRUD, shares, loans, passbook, withdrawals, commodities, guarantor flow, notifications (each module gets its own Redux slice)
2. **Phase 3 – Admin Endpoints:** Member management, financial CRUD, reports, role management
3. **Phase 4 – Push Notifications:** Expo Push Notifications + Firebase Cloud Messaging
4. **Phase 5 – File Downloads:** PDF generation for passbook, financial summaries

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
