# Endpoints list for LoginController

- [Login](#1-login)
- [Refrshes token](#2-refresh-token)
- [Registration user](#3-registration-user-public-api)

## 1. Login
### To get jwt and refresh token.
### Expiration for token by default JWT_TTL=3600
### Expiration for refresh_token by default JWT_REFRESH_TTL=7200
### Method: POST
```
/api/login_check
```
### Response:
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpYXQiOjE3MTg5NjE5NDIsImV4cCI6MTcxODk2NTU0Miwicm9sZXMiOlsiUk9MRV9VU0VSIl0sInVzZXJuYW1lIjoic3N2eXJ5ZGVua29Ad2VzdGxhbmRpbnN1cmFuY2UuY2EifQ.pICJ224C8k62HNQKXa5YZLTv3M8KdJdV5jVnsQCBWPE7Ttm0jHZm4tfVf35KH9eIfORwJP_HmSNM06WhWiX9VYllsCLwqvxTCHz-aIiJd7M9-tM49SLPtY8rC0hLzp7rTz2M8NGPCx0DEVv5QfkNv_kWlaGeAoMWfC52P3d4zKe2NRcjF0CanlWCSB8LdAAHghuvpdfhzJN8MfhUQkdOp7NEBgRry5c2QkBlPEOVBQwMC6QP4ikGcpciaBCVK8QyduO0iNmwscKMQuIB3IXx721yZHqMHKLOmpF9pZJO57m4_EYytJjiB8zMRRXsdAqnY19TAelyxZVHuBb74Dzc4g",
  "refresh_token": "c9605b5b878156dcb7099870b73b369b0a09cb6a85c8bf37856a18d6cce9605218e4972e5106fee9f912f18d19c14c0d75acf4c0a7b6b6671776923f77601622",
  "refresh_token_expiration": 1718969142
}
```

## 2. Refresh token
### Regarding refresh token could be get new one token and refresh if refresh was configured for using only ones.
### Method: POST
```
/api/token/refresh
```
### Response:
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpYXQiOjE3MTg5NjIwODgsImV4cCI6MTcxODk2NTY4OCwicm9sZXMiOlsiUk9MRV9VU0VSIl0sInVzZXJuYW1lIjoic3N2eXJ5ZGVua29Ad2VzdGxhbmRpbnN1cmFuY2UuY2EifQ.uSYvgvH6oPMCXemtSLaPitDmpSdfi_PUB2VcYnofqOAWW8blMGUNdz7R2wqc-rxRhfvWSgFVy29uNIJx1DnCp1Qi1I4mNYQdtPZK32_RrLw1yEzXrPq_BrH0AlWH-BksprdXeAPNyMVfIb5rpRDqoHoalHFf2SU2g0PGFgC7AINytMBd4P7Csjl7vmCUO_qKTkXF1UpoMXK0Z_hC67yn5GHPwfOKtoUja0ezZPoCNEaJSviD52bzW5loEqNP1U8-X9pK5Dq0wzC9ECAk9_nZNzUvr6epZ_YtIwtvER9gDOQYecTUdusNklXkqVrKHCuA_sDUYDAN6odkg6VfsViv8w",
  "refresh_token": "78d10aea68617cc48d6be21c69c5640f8e31f5298256eb46c9f0cbc8cc30c9755b985dba7dce37158153462d082f32eafa4181db95e60ac04ca1bd5caf3da362",
  "refresh_token_expiration": 1718969288
}
```

## 3. Registration User. Public API
### Anyone can send request to registration
### Method: POST
```
/api/v1/user/registration
```
### Request:
```json
{
  "email": "q@q.com",
  "first_name": "first_name",
  "last_name": "last_name",
  "password": "111",
  "password_repeat": "111",
  "phone": "0633022666"
}
```
### Response:
```json
{
  "message": {
    "message": "User registered"
  },
  "entity": {
    "id": 2,
    "uuid": "01929f75-64a6-76c6-8491-78299688d4c0",
    "email": "q@q.com",
    "firstName": "first_name",
    "lastName": "last_name",
    "phone": "0633022666"
  }
}
```

Required fields:

```json
first_name
last_name
email
password
password_repeat
```

Unique fields:

```json
email
```

Case 1 - happy path, registering a new user:

Request:

```json
{
  "email": "some@dude.com",
  "first_name": "Some",
  "last_name": "Dude",
  "password": "111",
  "password_repeat": "111"
}
```

Response:

```json
{
    "message": {
        "message": "User registered"
    },
    "entity": {
        "id": "....",
        "uuid": "....",
        "email": "some@dude.com",
        "first_name": "Some",
        "last_name": "Dude"
    }
}
```

Case 2 - duplicate email is submitted:

Request:

```json
{
    "email": "duplicate_email@gmail.com",
    "first_name": "Some",
    "last_name": "Dude",
    "password": "111",
    "password_repeat": "111"
}
```

Response:

```json
{
    "message": {
        "message": "User registration error",
        "code": "0192598c-7ab3-76e0-b37a-34927ab3e0af"
    },
    "errors": {
        "email": [
            {
                "message": "Duplicate email address",
                "code": "01925982-773a-788b-90ea-c0a0decc94cf"
            }
        ]
    }
}
```

Case 3 - When posting empty password string OR if that key is missing in the payload:

Request:

```json
{
    "email": "some@dude.com",
    "first_name": "Some",
    "last_name": "Dude"
}
```

or

```json
{
    "email": "some@dude.com",
    "first_name": "Some",
    "last_name": "Dude",
    "password": null
}
```

or

```json
{
    "email": "some@dude.com",
    "first_name": "Some",
    "last_name": "Dude",
    "password": ""
}
```

Format response as:

```json
{
    "message": {
        "message": "User registration error",
        "code": "0192598c-7ab3-76e0-b37a-34927ab3e0af"
    },
    "errors": {
        "password": [
            {
                "message": "Password is required",
                "code": {{SYMFONY CODE}}
            }
        ]
    }
}


```

Case 4 - When posting invalid or empty password_repeat string OR if that key is missing in the payload:

Request:

```json
{
    "email": "some@dude.com",
    "first_name": "Some",
    "last_name": "Dude"
}
```

or

```json
{
    "email": "some@dude.com",
    "first_name": "Some",
    "last_name": "Dude",
    "password_repeat": null
}
```

or

```json
{
    "email": "some@dude.com",
    "first_name": "Some",
    "last_name": "Dude",    
    "password_repeat": ""
}
```

Format response as:

```json
{
    "message": {
        "message": "User registration error",
        "code": "0192598c-7ab3-76e0-b37a-34927ab3e0af"
    },
    "errors": {
        "password_repeat": [
            {
                "message": "Password verification is required",
                "code": {{SYMFONY CODE}}
            }
        ]
    }
}
```

Case 5 - password is not verified:

Request:

```json
{
    "email": "some@dude.com",
    "first_name": "Some",
    "last_name": "Dude",
    "password": "222",
    "password_repeat": "333"
}
```

Format response as:

```json
{
  "message": {
    "message": "User registration error",
    "code": "0192598c-7ab3-76e0-b37a-34927ab3e0af"
  },
  "errors": {
    "password_repeat": [
      {
        "message": "The passwords do not match",
        "code": "01925963-128c-765c-81a2-66fcb1837739"
      }
    ]
  }
}
```

Case 6 - posting empty first_name or last_name strings OR if those keys are missing in the payload:

Request:

```json
{
  "email": "some-RAND12847349@dude.com",
  "first_name": null,
  "last_name": "",
  "password": "111",
  "password_repeat": "111"
}
```
or
```json
{
  "email": "some@dude.com",
  "password": "111",
  "password_repeat": "111"
}
```
Response:

```json
{
  "message": {
    "message": "User registration error",
    "code": "0192598c-7ab3-76e0-b37a-34927ab3e0af"
  },
  "errors": {
    "first_name": [
      {
        "message": "First name is required",
        "code": {{SYMFONY CODE}}
    }
    ],
    "last_name": [
      {
        "message": "Last name is required",
        "code": {{SYMFONY CODE}}
    }
    ]
  }
}
```

Case 1 returns HTTP response code 200, all other cases return 4xx.