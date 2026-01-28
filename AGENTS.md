# AGENTS.md: AI Agent Instructions

This document provides essential, high-level instructions for AI agents to effectively understand and contribute to this project. For detailed human-oriented documentation, see `README.md`.

## Core Objective

This project is a Laravel-based proof-of-concept for **step-up authentication using Amazon Rekognition's Face Liveness** feature. The goal is to require a real-time face verification for users attempting to access sensitive application routes.

## Key Technologies & Conventions

- **Framework**: Laravel 10.x
- **Authentication**: Standard Laravel authentication (`Auth` facade, Eloquent User model).
- **Step-Up Auth**:
  - Middleware: `app/Http/Middleware/RequireStepUp.php` checks if a user has recently passed a liveness check.
  - Session: A successful check is stored in the user's session (`stepup_verified_at`).
- **Amazon Web Services**:
  - **Rekognition**: The core service for face liveness.
    - Service class: `app/Services/RekognitionService.php`. This is the primary interface for all Rekognition API calls.
    - Controller: `app/Http/Controllers/RekognitionController.php` exposes Rekognition functionality via API routes.
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
    1.  Register a new user or log in.
    2.  Navigate to a page that triggers a sensitive action (e.g., one protected by the `require.stepup` middleware).
    3.  The application will redirect you to the `/step-up` route.
    4.  The frontend will initiate the Face Liveness check.
    5.  Upon successful verification, the backend marks the session as verified, and you will be redirected to the originally requested sensitive page.

## Key Files & Directories

- `routes/web.php`: Defines all web routes, including the step-up authentication flow and Rekognition API endpoints.
- `app/Http/Controllers/RekognitionController.php`: Handles requests for creating and verifying Face Liveness sessions.
- `app/Http/Controllers/StepUpController.php`: Manages the user-facing step-up verification flow.
- `app/Services/RekognitionService.php`: A wrapper for the AWS Rekognition SDK. **All new Rekognition-related logic should be added here.**
- `app/Services/StsService.php`: A wrapper for the AWS STS SDK.
- `app/Http/Middleware/RequireStepUp.php`: The middleware that protects sensitive routes.
- `resources/views/stepup.blade.php`: The Blade view that contains the frontend logic for the Face Liveness check.
- `.env.example`: The template for environment variables. Refer to this for required AWS settings.

## Agent Instructions & Constraints

- **DO NOT** embed AWS credentials directly in the code. Use the `env()` helper to read them from the environment.
- **DO** abstract all AWS API calls into the service classes (`RekognitionService`, `StsService`). Controllers should not directly interact with the AWS SDK.
- **When adding new features**, first check `routes/web.php` to see if a similar route already exists.
- **For database changes**, create a new migration file using `php artisan make:migration`.
- **Adhere to Laravel conventions** for naming, routing, and code structure.
