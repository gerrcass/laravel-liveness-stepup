# Amazon Rekognition Step-Up Authentication with Face Liveness - Laravel Demo

This project is a proof-of-concept demonstrating how to implement **step-up authentication** in a Laravel application using **Amazon Rekognition's Face Liveness** feature. This allows you to require an additional, high-assurance verification step for users attempting to access sensitive parts of your application.

## Table of Contents

- [Project Overview](#project-overview)
- [How it Works](#how-it-works)
- [Core Technologies](#core-technologies)
- [Setup and Installation](#setup-and-installation)
- [Configuration](#configuration)
- [Running the Application](#running-the-application)
- [Key Application Flow](#key-application-flow)
- [Project Structure](#project-structure)

## Project Overview

In many applications, certain actions (e.g., changing account details, transferring funds, accessing admin panels) are more sensitive than others. Standard password-based authentication might not be sufficient to protect these actions. Step-up authentication provides an additional layer of security by requiring the user to re-verify their identity in real-time.

This project uses Amazon Rekognition's Face Liveness detection to confirm that a live user is present and not a bad actor using a spoofed image or video.

## How it Works

1.  A user logs into the application using their standard username and password.
2.  When they try to access a protected route (a "special operation"), the application checks if they have recently completed a step-up verification.
3.  If they haven't, they are redirected to a verification page.
4.  The frontend, using the Rekognition browser SDK, initiates a Face Liveness check. This involves creating a session with the Rekognition API and guiding the user to position their face correctly in front of their device's camera.
5.  Rekognition analyzes the video stream to determine if it's a live person.
6.  Upon successful verification, the backend marks the user's session as "verified" for a limited time.
7.  The user is then redirected to the sensitive page they originally requested.

## Core Technologies

- **Framework**: Laravel 10.x
- **Authentication**: Standard Laravel session-based authentication.
- **Step-Up Authentication**:
    - **Amazon Rekognition**: For Face Liveness detection.
    - **AWS SDK for PHP**: To communicate with AWS services from the Laravel backend.
- **Database**: Any Laravel-compatible database (e.g., MySQL, PostgreSQL).
- **Frontend**: Blade templates with vanilla JavaScript for interacting with the Rekognition SDK.

## Setup and Installation

1.  **Clone the Repository**
    ```bash
    git clone https://github.com/your-repo/amazon_rekognition.git
    cd amazon_rekognition
    ```

2.  **Install Dependencies**
    Install both Composer and NPM dependencies.
    ```bash
    composer install
    npm install
    ```

3.  **Create Environment File**
    Copy the example environment file and generate an application key.
    ```bash
    cp .env.example .env
    php artisan key:generate
    ```

4.  **Configure Database**
    Open the `.env` file and set your database connection details (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

5.  **Run Migrations and Seeders**
    This will create the necessary tables and seed the database with initial data (e.g., user roles).
    ```bash
    php artisan migrate --seed
    ```

## Configuration

### AWS Credentials

To use Amazon Rekognition, you need to configure your AWS credentials. Add the following to your `.env` file:

```dotenv
AWS_ACCESS_KEY_ID=your_aws_access_key
AWS_SECRET_ACCESS_KEY=your_aws_secret_key
AWS_DEFAULT_REGION=us-east-1
```

The IAM user associated with these credentials needs permissions for Rekognition and STS. A sample policy would include:

- `rekognition:CreateFaceLivenessSession`
- `rekognition:GetFaceLivenessSessionResults`
- `sts:GetSessionToken`

## Running the Application

1.  **Start the Development Server**
    ```bash
    php artisan serve
    ```

2.  **Build Frontend Assets**
    Run the Vite development server in a separate terminal.
    ```bash
    npm run dev
    ```

The application will be available at `http://localhost:8000`.

## Key Application Flow

1.  **Registration/Login**: Register a new user or log in with an existing one.
2.  **Accessing a Protected Route**: After logging in, try to access a sensitive area of the application. The project includes a "Special Operation" button on the dashboard for this purpose.
3.  **Step-Up Verification**: You will be redirected to the step-up verification page. Follow the on-screen instructions to complete the Face Liveness check.
4.  **Access Granted**: Upon successful verification, you will be redirected to the special operation result page, confirming that you have passed the step-up check.

## Project Structure

- `app/Http/Controllers/RekognitionController.php`: Handles API requests from the frontend to create and check Face Liveness sessions.
- `app/Http/Controllers/StepUpController.php`: Manages the user-facing part of the step-up flow.
- `app/Services/RekognitionService.php`: A service class that encapsulates all interactions with the Amazon Rekognition API.
- `app/Services/StsService.php`: A service class for generating temporary AWS credentials using the Security Token Service (STS).
- `app/Http/Middleware/RequireStepUp.php`: The middleware responsible for protecting sensitive routes and triggering the step-up flow.
- `routes/web.php`: Defines all application routes, including the protected routes and the API endpoints for Rekognition.
- `resources/views/stepup.blade.php`: The view file containing the frontend logic for the Face Liveness check.
- `AGENTS.md`: A file with instructions for AI agents working on this project.