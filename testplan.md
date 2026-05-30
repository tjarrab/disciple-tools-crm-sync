## **1\. The Foundation: Unit Tests (Brain Monkey \+ PHPUnit)**

The bulk of your tests should live here. Because unit tests do not load WordPress or a database, they execute in milliseconds. This provides instant feedback as you write code.

* **The Goal:** Test your pure business logic, mathematical calculations, API response parsing, and error handling.  
* **The Architecture Rule:** To make this work on a new plugin, you must separate your logic from WordPress. Do not mix database queries with data processing. Build pure PHP classes (e.g., ContactMetricsCalculator) and pass the raw data into them.  
* **How it Works:**  
  * You use **PHPUnit** to run the tests.  
  * You use **Brain Monkey** to mock any WordPress hooks or functions your logic accidentally touches.  
  * If you need to test how your plugin handles a failed external API call, you mock the wp\_remote\_get response to return an error, rather than taking down your internet connection.

## **2\. The Middle: Integration Tests (WP Test Library \+ PHPUnit)**

Mocking WordPress is great for speed, but you eventually need to prove that your plugin actually communicates correctly with the real WordPress and Disciple.Tools database.

* **The Goal:** Test database interactions, Custom Post Type (CPT) registrations, metadata saving, and complex SQL queries.  
* **The Architecture Rule:** Keep these tests isolated strictly to your "WordPress wrapper" classes. If you have a class that saves a new DT Contact, use this layer to verify the data actually lands in the wp\_postmeta table.  
* **How it Works:**  
  * You use the official install-wp-tests.sh script (included in the DT Plugin Starter Template) to scaffold a headless, temporary WordPress database.  
  * You still use **PHPUnit** to run the tests, but this time it runs within the live WordPress environment.  
  * These run slower (seconds or minutes instead of milliseconds), so you run them less frequently—usually before committing code.

## **3\. The Peak: End-to-End (E2E) Tests (Cypress)**

Modern DT plugins often rely heavily on JavaScript, React, and complex UI interactions. PHP tests cannot verify if a button click opens the correct modal or if a frontend map renders properly.

* **The Goal:** Test the user journey, frontend interactions, and ensure the UI correctly triggers the backend API endpoints.  
* **The Architecture Rule:** Focus strictly on critical user flows. E2E tests are brittle and slow. Do not write an E2E test to check a math calculation; write it to ensure the user can successfully submit a form and see the success message.  
* **How it Works:**  
  * You use **Cypress** to spin up a browser.  
  * The script logs in as a specific DT user role (e.g., Dispatcher or Multiplier), navigates to your plugin's page, interacts with the DOM, and asserts that the correct elements appear on screen.

## **The CI/CD Workflow (Bringing it Together)**

When you combine these three layers into a modern GitHub Actions pipeline, the workflow for a new feature looks like this:

1. **Local Development:** You write code and run your Brain Monkey Unit Tests locally. They run instantly, catching logic errors as you type.  
2. **The Push:** You commit your code and open a Pull Request on GitHub.  
3. **The Pipeline:** GitHub Actions automatically takes over:  
   * **Step 1 (Static Analysis):** Runs PHP\_CodeSniffer to ensure your code matches WP/DT coding standards. *(Takes 10 seconds)*  
   * **Step 2 (Unit):** Runs the Brain Monkey test suite. *(Takes 5 seconds)*  
   * **Step 3 (Integration):** Spins up the temporary WP database and runs your integration suite. *(Takes 1-2 minutes)*  
   * **Step 4 (E2E):** Boots a full Disciple.Tools environment in Docker and runs Cypress UI tests. *(Takes 3-5 minutes)*

If any step fails, the Pull Request is blocked.

