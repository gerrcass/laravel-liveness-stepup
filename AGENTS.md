# AGENTS.md: AI Agent Instructions

This document provides essential, high-level instructions for AI agents to effectively understand and contribute to this project. For detailed human-oriented documentation, see `README.md`.

## Core Objective

This project is a Laravel-based proof-of-concept for **step-up authentication using Amazon Rekognition's Face Recognition and Face Liveness** features. The goal is to require face verification for users attempting to access sensitive application routes. Users can register with either a reference face image or Face Liveness, and subsequent verifications use the corresponding method.

## Key Technologies & Conventions

- **Framework**: Laravel 10.x
- **Authentication**: Standard Laravel authentication (`Auth` facade, Eloquent User model).
- **Step-Up Auth**:
    - Middleware: `app/Http/Middleware/RequireStepUp.php` checks if a user has recently passed a face verification check.
    - Session: A successful check is stored in the user's session (`stepup_verified_at`) with a configurable timeout (default 900 seconds, configurable via `STEPUP_TIMEOUT` environment variable).
    - **Traditional Verification**: Uses Rekognition's `SearchFacesByImage` API to compare uploaded images against a face collection. Requires confidence >= 60% and matching `ExternalImageId` (user ID).
    - **Face Liveness Verification**: Uses `GetFaceLivenessSessionResults` to extract reference image from completed liveness session, then searches the face collection using `SearchFacesByImage`. Requires both liveness confidence >= 60% and face similarity >= 60%.
- **Amazon Web Services**:
    - **Rekognition**: The core service for face recognition and liveness detection.
        - Service class: `app/Services/RekognitionService.php`. This is the primary interface for all Rekognition API calls.
        - Uses `indexFaces` to register user faces in a collection during registration.
        - Uses `searchFacesByImage` to verify faces during step-up authentication (traditional method).
        - Uses `CreateFaceLivenessSession` and `GetFaceLivenessSessionResults` for Face Liveness verification.
        - **S3 Integration**: When `AWS_S3_BUCKET` is configured, Face Liveness sessions store reference images and audit images in S3. The system automatically downloads images from S3 when needed via `getReferenceImageBytes()` method.
        - Controller: `app/Http/Controllers/RekognitionController.php` exposes Rekognition API routes. Handles both traditional face recognition and Face Liveness endpoints.
        - Key methods: `indexFaceFromLivenessSession()`, `verifyFaceWithLiveness()`, `createFaceLivenessSession()`, `getFaceLivenessSessionResults()`.
    - **STS (Security Token Service)**:
        - Service class: `app/Services/StsService.php`.
        - Purpose: Generates temporary, short-lived AWS credentials (15-minute expiration). This allows the frontend to make direct calls to the Rekognition API without exposing long-term secrets.
        - Used by Face Liveness frontend component to authenticate directly with Rekognition.
- **Dependencies**:
    - `aws/aws-sdk-php`: The official AWS SDK for PHP.
    - `spatie/laravel-permission`: Used for role-based access control (e.g., distinguishing between standard users and privileged users).
    - `@aws-amplify/ui-react-liveness`: AWS Amplify React component for Face Liveness UI.
    - `react`: React library for Face Liveness component.
    - `vite`: Build system for compiling React assets.
- **Frontend**: 
    - **React**: For Face Liveness UI components
    - **AWS Amplify UI**: `@aws-amplify/ui-react-liveness` - `FaceLivenessDetectorCore` component
    - **Blade Templates**: For traditional forms and layouts
    - **Custom Credentials Provider**: STS service provides temporary credentials to frontend for secure AWS API calls

## Development Workflow

1.  **Environment Setup**:
    - Copy `.env.example` to `.env`.
    - Configure `DB_*` variables for your local database.
    - Configure `AWS_*` variables with valid AWS credentials (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`). The IAM user associated with these credentials needs `rekognition:*`, `rekognition:StartFaceLivenessSession`, `sts:GetSessionToken`, and `s3:*` permissions.
    - (Optional) Configure `AWS_S3_BUCKET` for Face Liveness image storage.
    - Run `composer install`.
    - Run `npm install`.
    - Run `php artisan key:generate`.
    - Run `php artisan migrate --seed` to set up the database, including roles.
    - Run `npm run build` to compile React assets.

2.  **Running the Application**:
    - Use `php artisan serve` to start the local development server.
    - Use `npm run dev` to watch and build frontend assets (or `npm run build` for production build).

3.  **Testing Face Liveness Registration**:
    1. Navigate to the `/register` page.
    2. Fill in user details (name, email, password, role).
    3. Select "Face Liveness (Video Selfie)" as registration method.
    4. Click "Start Face Liveness Check" button.
    5. Complete the Face Liveness challenge (video selfie with movement/light instructions).
    6. Upon completion, the "Registrar" button becomes enabled.
    7. Click "Registrar" to complete user registration.
    8. User is automatically logged in and redirected to dashboard.

4.  **Testing Step-Up Authentication**:
    1. Register or log in as a user with the 'privileged' role.
    2. Navigate to a page that triggers a sensitive action (e.g., "Perform Special Operation" button on dashboard).
    3. The application will redirect you to the `/step-up` route if verification hasn't been completed recently (within `STEPUP_TIMEOUT` seconds).
    4. **For traditional users**: Upload a face image that matches the registered user's face.
    5. **For Face Liveness users**: Click "Start Face Liveness Check" and complete the Face Liveness challenge.
    6. Upon successful verification (confidence >= 60% and matching user ID), the backend marks the session as verified, and you will be redirected to the originally requested sensitive page.
    7. Subsequent access attempts within the timeout period will bypass verification.

## Key Files & Directories

- `routes/web.php`: Defines all web routes, including the step-up authentication flow and Rekognition API endpoints.
- `app/Http/Controllers/RekognitionController.php`: Exposes Rekognition endpoints. Handles both traditional face recognition and Face Liveness endpoints. Key method `completeLivenessRegistrationGuest()` calls `GetFaceLivenessSessionResults` FIRST to avoid race condition with AWS Amplify UI component.
- `app/Http/Controllers/StepUpController.php`: Manages the user-facing step-up verification flow. Handles image uploads and Face Liveness verification.
- `app/Http/Controllers/Auth/RegisterController.php`: Updated to support both registration methods with Face Liveness integration. Uses stored session data from completion endpoint.
- `app/Services/RekognitionService.php`: A wrapper for the AWS Rekognition SDK. **All new Rekognition-related logic should be added here.** Key methods: `indexFace()` (traditional registration), `searchFace()` (traditional verification), `indexFaceFromLivenessSession()` (Face Liveness registration), `verifyFaceWithLiveness()` (Face Liveness verification), `createFaceLivenessSession()` / `getFaceLivenessSessionResults()` for Face Liveness API, `getReferenceImageBytes()` (S3 image download), `cleanLivenessResultForStorage()` (removes binary data).
- `app/Services/StsService.php`: A wrapper for the AWS STS SDK.
- `app/Http/Middleware/RequireStepUp.php`: The middleware that protects sensitive routes. Checks session for `stepup_verified_at` timestamp and validates it against `STEPUP_TIMEOUT`.
- `app/Models/UserFace.php`: Model for user face data with `face_data`, `liveness_data`, `verification_status`, `registration_method`, and `last_verified_at` fields.
- `resources/js/components/FaceLivenessDetector.jsx`: React component for Face Liveness UI with session management and error handling. Callback suppresses component errors when backend has already processed successfully.
- `resources/js/app.js`: Main JavaScript entry point with global Face Liveness initialization function.
- `resources/views/auth/register.blade.php`: Registration form with method selection and Face Liveness integration.
- `resources/views/auth/stepup.blade.php`: Step-up verification with method-specific UI (image upload or Face Liveness).
- `resources/views/stepup_post_redirect.blade.php`: Intermediate page for POST redirects in step-up flow.
- `resources/views/special_operation_result.blade.php`: Success page showing verification details.
- `resources/views/layouts/app.blade.php`: Main layout with CSRF token and Vite asset loading.
- `.env.example`: The template for environment variables. Refer to this for required AWS settings.
- `lessons.md`: Documented lessons learned during implementation.

## Agent Instructions & Constraints

- **DO NOT** embed AWS credentials directly in the code. Use the `env()` helper to read them from the environment.
- **DO** abstract all AWS API calls into the service classes (`RekognitionService`, `StsService`). Controllers should not directly interact with the AWS SDK.
- **When adding new features**, first check `routes/web.php` to see if a similar route already exists.
- **For database changes**, create a new migration file using `php artisan make:migration`.
- **Adhere to Laravel conventions** for naming, routing, and code structure.
- **Face Collection**: The application uses a Rekognition face collection (configurable via `REKOGNITION_COLLECTION_NAME` environment variable, default: 'users') to store registered faces. Each face is indexed with the user's ID as the `ExternalImageId`.
- **Verification Threshold**: Face verification requires a similarity confidence of at least `REKOGNITION_CONFIDENCE_THRESHOLD` (default: 60%). For Face Liveness, both liveness confidence >= 60% and face similarity >= 60% are required. Lower threshold was set to reduce false negatives during testing.
- **Session Timeout**: Step-up verification status expires after `STEPUP_TIMEOUT` seconds (default: 900, configurable via environment variable).
- **S3 Prefix Configuration**: S3 key prefixes are configurable via environment variables:
    - `AWS_S3_IMAGE_PREFIX`: Prefix for traditional face registration images (default: 'image-sessions/')
    - `AWS_S3_LIVENESS_PREFIX`: Prefix for Face Liveness session files (default: 'face-liveness-sessions/')
- **S3 Image Storage**: When `AWS_S3_BUCKET` is configured, Face Liveness sessions store reference images and audit images in S3. The system automatically downloads images from S3 when needed for indexing or verification.
- **Binary Data Handling**: Face Liveness session results may contain binary image data that cannot be JSON encoded. The system implements `cleanLivenessResultForStorage()` to remove binary data before storage and `getReferenceImageBytes()` to download from S3 when needed.
- **S3Object vs Bytes**: When `AWS_S3_BUCKET` is configured, Face Liveness results include `S3Object` instead of `Bytes` in `ReferenceImage`. The `getReferenceImageBytes()` method handles both cases transparently.
- **CSRF Token**: Ensure that the `<meta name="csrf-token">` tag is present in the HTML layout for all pages that use Face Liveness API. The React component reads this token to include in API requests.
- **Race Condition Handling**: The AWS Amplify UI component internally calls `GetFaceLivenessSessionResults` which can cause a race condition. The `completeLivenessRegistrationGuest` endpoint calls this API FIRST and stores results in session. The React component callback suppresses component errors when backend has already processed successfully.
- **Conditional Validation**: When using `Rule::excludeIf` or `exclude_if`, empty fields are completely skipped during validation, preventing the `ConvertEmptyStringsToNull` middleware from causing "must be a string" errors.
- **Code Scope**: Always verify that error handling code is placed at the correct scope level. Code inside a conditional only runs when that condition is true. Error handlers should typically be outside the main conditional block.
- **POST Redirects**: When redirecting after successful verification for POST routes, use `stepup_post_redirect` view with hidden form and JavaScript auto-submit to maintain POST data for protected operations.
- **Session Data**: Generate and store verification data in session BEFORE any redirects to ensure it's available throughout the flow.

## Database Schema - user_faces Table

The `user_faces` table stores face verification data with the following fields:

- **face_data** (JSON): Contains Rekognition `IndexFaces` response. The `ExternalImageId` field stores the user's ID (`user_id`), enabling face matching during verification.
- **liveness_data** (JSON): Contains complete Face Liveness session results including `SessionId`, `Confidence`, `ReferenceImage` (S3Object with Bucket and Name), and `AuditImages` array for audit trail.
- **verification_status**: Current verification state - `verified`, `pending`, or `failed`.
- **registration_method**: Either `image` (traditional registration) or `liveness` (video selfie).
- **last_verified_at**: Timestamp of last successful verification.

### Design Rationale:

1. **Why separate `face_data` and `liveness_data`?**
   - `face_data`: Stores the response from `IndexFaces` API, which is used during step-up verification to match against uploaded images.
   - `liveness_data`: Stores the complete liveness session for audit purposes, including confidence scores, S3 references to reference/audit images, and session metadata.
   - Separation allows efficient verification (using `face_data`) while maintaining complete audit trail (in `liveness_data`).

2. **Why `verification_status`?**
   - Allows tracking verification state independently of registration method.
   - Can be updated during step-up verification without re-indexing the face.
   - Useful for admin monitoring and security auditing.

## Duplicate Face Registration

**Current Behavior**: The application allows multiple user accounts to register with the same face. This is intentional for testing purposes.

**Implications**:
- When verifying a user's identity, the system searches for faces matching both the reference image AND the `ExternalImageId` (user_id).
- If multiple users have similar faces, the verification will still succeed for each individual user as long as their `ExternalImageId` matches.
- This design allows legitimate use cases (same person having multiple accounts) while maintaining security.

**To Prevent Duplicate Registration** (if desired):
- Before creating a new user face, search the Rekognition collection for faces with high similarity.
- If a match is found, reject the registration or show a warning.
- Example logic can be added to `RegisterController::register()` before creating the UserFace record.
