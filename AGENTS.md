# AGENTS.md: AI Agent Instructions

This document provides essential, high-level instructions for AI agents to effectively understand and contribute to this project. For detailed human-oriented documentation, see `README.md`.

## Core Objective

This project is a Laravel-based proof-of-concept for **step-up authentication using Amazon Rekognition's Face Recognition** feature. The goal is to require face verification for users attempting to access sensitive application routes. Users register with a reference face image, and subsequent verifications compare uploaded images against this reference using Rekognition's `SearchFacesByImage` API.

## Key Technologies & Conventions

- **Framework**: Laravel 10.x
- **Authentication**: Standard Laravel authentication (`Auth` facade, Eloquent User model).
- **Step-Up Auth**:
    - Middleware: `app/Http/Middleware/RequireStepUp.php` checks if a user has recently passed a face verification check.
    - Session: A successful check is stored in the user's session (`stepup_verified_at`) with a configurable timeout (default 900 seconds, configurable via `STEPUP_TIMEOUT` environment variable).
    - Verification: Uses Rekognition's `SearchFacesByImage` API to compare uploaded images against a face collection. Requires confidence >= 85% and matching `ExternalImageId` (user ID).
- **Amazon Web Services**:
    - **Rekognition**: The core service for face recognition.
        - Service class: `app/Services/RekognitionService.php`. This is the primary interface for all Rekognition API calls.
        - Uses `indexFaces` to register user faces in a collection during registration.
        - Uses `searchFacesByImage` to verify faces during step-up authentication.
        - Controller: `app/Http/Controllers/RekognitionController.php` exposes Rekognition API routes. The main step-up flow uses SearchFacesByImage via StepUpController; optional Face Liveness session endpoints are also available.
    - **STS (Security Token Service)**:
        - Service class: `app/Services/StsService.php`.
        - Purpose: Generates temporary, short-lived AWS credentials. This allows the frontend to make direct calls to the Rekognition API without exposing long-term secrets.
- **Dependencies**:
    - `aws/aws-sdk-php`: The official AWS SDK for PHP.
    - `spatie/laravel-permission`: Used for role-based access control (e.g., distinguishing between standard users and privileged users).
- **Frontend**: The frontend is expected to be a simple Blade template that uses JavaScript to interact with the Rekognition browser SDK.

## Development Workflow

1.  **Environment Setup**:
    - Copy `.env.example` to `.env`.
    - Configure `DB_*` variables for your local database.
    - Configure `AWS_*` variables with valid AWS credentials (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`). The IAM user associated with these credentials needs `rekognition:*` and `sts:GetSessionToken` permissions.
    - Run `composer install`.
    - Run `npm install`.
    - Run `php artisan key:generate`.
    - Run `php artisan migrate --seed` to set up the database, including roles.

2.  **Running the Application**:
    - Use `php artisan serve` to start the local development server.
    - Use `npm run dev` to build frontend assets.

3.  **Testing Step-Up Authentication**:
    1.  Register a new user (with a reference face image) or log in.
    2.  Navigate to a page that triggers a sensitive action (e.g., one protected by the `require.stepup` middleware). Only users with the 'privileged' role can access these routes.
    3.  The application will redirect you to the `/step-up` route if verification hasn't been completed recently (within `STEPUP_TIMEOUT` seconds).
    4.  Upload a face image that matches the registered user's face.
    5.  Upon successful verification (confidence >= 85% and matching user ID), the backend marks the session as verified, and you will be redirected to the originally requested sensitive page.
    6.  Subsequent access attempts within the timeout period will bypass verification.

## Key Files & Directories

- `routes/web.php`: Defines all web routes, including the step-up authentication flow and Rekognition API endpoints.
- `app/Http/Controllers/RekognitionController.php`: Exposes Rekognition endpoints. Main step-up flow uses SearchFacesByImage via StepUpController; optional Face Liveness session methods are documented as such.
- `app/Http/Controllers/StepUpController.php`: Manages the user-facing step-up verification flow. Handles image uploads and calls `RekognitionService::searchFace()` to verify faces.
- `app/Services/RekognitionService.php`: A wrapper for the AWS Rekognition SDK. **All new Rekognition-related logic should be added here.** Key methods: `indexFace()` (registration), `searchFace()` (main step-up verification via SearchFacesByImage), and optional `createFaceLivenessSession()` / `getFaceLivenessSessionResults()` for the Face Liveness API.
- `app/Services/StsService.php`: A wrapper for the AWS STS SDK.
- `app/Http/Middleware/RequireStepUp.php`: The middleware that protects sensitive routes. Checks session for `stepup_verified_at` timestamp and validates it against `STEPUP_TIMEOUT`.
- `resources/views/auth/stepup.blade.php`: The Blade view that contains a form for uploading face images for verification.
- `.env.example`: The template for environment variables. Refer to this for required AWS settings.

## Agent Instructions & Constraints

- **DO NOT** embed AWS credentials directly in the code. Use the `env()` helper to read them from the environment.
- **DO** abstract all AWS API calls into the service classes (`RekognitionService`, `StsService`). Controllers should not directly interact with the AWS SDK.
- **When adding new features**, first check `routes/web.php` to see if a similar route already exists.
- **For database changes**, create a new migration file using `php artisan make:migration`.
- **Adhere to Laravel conventions** for naming, routing, and code structure.
- **Face Collection**: The application uses a Rekognition face collection (default: 'users') to store registered faces. Each face is indexed with the user's ID as the `ExternalImageId`.
- **Verification Threshold**: Face verification requires a similarity confidence of at least 85% (configurable in `RekognitionService::searchFace()`).
- **Session Timeout**: Step-up verification status expires after `STEPUP_TIMEOUT` seconds (default: 900, configurable via environment variable).
