SLOT4U – API SPECIFICATION v1.0
Cél

A Slot4U rendszer API-first architektúrával készül.

A frontend, mobil alkalmazás, külső integrációk és AI ügynökök ugyanazt az API réteget használják.

Technológia:

Laravel 12+
REST API
JSON
OAuth2 / API Key
Webhook rendszer
Queue alapú integrációk
Multi Tenant architektúra
API ARCHITECTURE
Base URL
https://api.slot4u.hu/v1/

vagy tenant esetén:

https://api.slot4u.hu/v1/{tenant}

Példa:

https://api.slot4u.hu/v1/functionalfit
AUTHENTICATION
Tenant API Key
Authorization: Bearer API_KEY

Jogosultságok:

read
write
admin
OAuth2

Későbbi mobil app és külső alkalmazások számára.

CORE RESOURCES
Tenants
GET /tenants
GET /tenants/{id}
POST /tenants
PUT /tenants/{id}
DELETE /tenants/{id}
Users
GET /users
GET /users/{id}
POST /users
PUT /users/{id}
DELETE /users/{id}
Customers
GET /customers
GET /customers/{id}
POST /customers
PUT /customers/{id}
DELETE /customers/{id}
Employees
GET /employees
GET /employees/{id}
POST /employees
PUT /employees/{id}
DELETE /employees/{id}
Locations
GET /locations
POST /locations
PUT /locations/{id}
DELETE /locations/{id}
Rooms
GET /rooms
POST /rooms
PUT /rooms/{id}
DELETE /rooms/{id}
Services
GET /services
GET /services/{id}
POST /services
PUT /services/{id}
DELETE /services/{id}
Events
GET /events
GET /events/{id}
POST /events
PUT /events/{id}
DELETE /events/{id}
BOOKING API
Available Slots
GET /available-slots

Szűrők:

date
employee_id
location_id
room_id
service_id
Booking Create
POST /bookings

Példa:

{
  "service_id": 12,
  "employee_id": 5,
  "customer_id": 100,
  "start": "2026-07-12 14:00"
}
Booking Modify
PUT /bookings/{id}
Booking Cancel
POST /bookings/{id}/cancel
Booking Status

Lehetséges státuszok:

pending
confirmed
cancelled
completed
no_show
waiting_list
requested
WAITING LIST API
POST /waiting-list
GET /waiting-list
DELETE /waiting-list/{id}
MEMBERSHIP & PASSES
Bérletek
GET /passes
POST /passes
PUT /passes/{id}
Felhasználás
POST /passes/{id}/consume
PAYMENT API
Fizetés indítása
POST /payments
Fizetés állapot
GET /payments/{id}
Refund
POST /payments/{id}/refund
INVOICE API
Számlák
GET /invoices
GET /invoices/{id}
PDF
GET /invoices/{id}/pdf
DOCUMENT API
Dokumentumok
GET /documents
POST /documents
DELETE /documents/{id}
REPORTING API
Dashboard
GET /reports/dashboard
Revenue
GET /reports/revenue
Employee Utilization
GET /reports/utilization
PUBLIC EMBEDDABLE API

Külső weboldalak számára.

Szolgáltatások
GET /public/services
Szabad időpontok
GET /public/available-slots
Foglalás
POST /public/bookings
WEBHOOK SYSTEM
Események
booking.created
booking.updated
booking.cancelled

booking.reminder_sent

customer.created
customer.updated

employee.created

payment.started
payment.success
payment.failed

invoice.created

pass.created
pass.expired

document.uploaded
GOOGLE CALENDAR INTEGRATION
Funkciók

Foglalás létrejötte:

booking.created

↓

Google Calendar Event

Módosítás:

booking.updated

↓

Google Event Update

Törlés:

booking.cancelled

↓

Google Event Delete

Kétirányú szinkron:

Google → Slot4U
Slot4U → Google
GOOGLE ANALYTICS 4

Mért események:

view_service
select_service

view_slot
select_slot

booking_started
booking_completed

payment_started
payment_completed
FACEBOOK PIXEL

Meta Events:

ViewContent

Lead

InitiateCheckout

Purchase

Schedule
MAILCHIMP

Szinkronizált adatok:

customer.created
customer.updated
booking.completed

Lista:

Tag:
Pilates

Tag:
Masszázs

Tag:
Pszichológia
MAILERLITE

Szinkron:

Új ügyfél

Lemondott ügyfél

VIP ügyfél
ACTIVECAMPAIGN

Automatizmusok:

Első foglalás

Nincs foglalás 30 napja

Bérlet lejár

Születésnap
GOOGLE BUSINESS PROFILE

Tervezett integráció:

Foglalási link
Reserve with Google
Szolgáltatás lista szinkron
Google Business Profile
↔
Slot4U
AI AGENT API (2026+)

Külső AI rendszerek számára.

Példa:

POST /ai/find-slot

Input:

{
  "service": "Masszázs",
  "duration": 60,
  "preferred_date": "2026-07-12"
}

Output:

{
  "available_slots": [...]
}
ENTERPRISE API
Franchise riportok
GET /enterprise/tenants
GET /enterprise/revenue
GET /enterprise/bookings
JÖVŐBENI INTEGRÁCIÓK

Prioritási sorrend:

MVP
Google Calendar
GA4
Facebook Pixel
Mailchimp
MailerLite
ActiveCampaign
PRO
Stripe
Barion
Billingo
Számlázz.hu
ENTERPRISE
Google Business Profile
Zapier
Make.com
HubSpot
MiniCRM
Salesforce
2027+
WhatsApp Business
Apple Calendar
Microsoft 365 Calendar
AI Agents (OpenAI, Gemini, Claude)
MCP Server támogatás