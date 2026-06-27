PRODUCT VISION
SLOT4U – Multi-Tenant Booking Platform
1. Product Overview

A SLOT4U egy modern, SaaS alapú online foglalási és ügyfélkezelő rendszer, amely lehetővé teszi szolgáltatók számára foglalások, ügyfelek, dolgozók, események és digitális szolgáltatások kezelését egyetlen platformon.

A rendszer célja, hogy egyetlen kódbázisból kiszolgáljon:

egyéni vállalkozókat
egészségügyi szolgáltatókat
edzőtermeket
szépségipari szolgáltatókat
coaching vállalkozásokat
stúdiókat
oktatási szolgáltatókat
több telephelyes cégeket

A platform teljesen több-bérlős (Multi-Tenant) SaaS modellben működik.

2. Vision Statement

„A SLOT4U célja, hogy Európa egyik legmodernebb és legjobban automatizálható foglalási rendszere legyen, amely egyszerre szolgálja ki az egyéni szakembereket és a több telephelyes vállalkozásokat.”

3. Core Mission

A foglalás ne adminisztratív teher legyen.

A rendszer:

automatizálja az időpontkezelést
csökkenti a telefonos egyeztetéseket
növeli a foglalások számát
javítja az ügyfélélményt
segíti az üzleti növekedést
4. Target Audience
Egészségügy
pszichológus
pszichiáter
gyógytornász
dietetikus
Fitness
személyi edző
jógastúdió
Wellness
masszőr
szépségszalon
Tanácsadás
coach
üzleti mentor
karrier tanácsadó
5. Product Philosophy

A rendszer tervezési alapelvei:

Egyszerűség

Egy ügyfél 30 másodpercen belül tudjon foglalni.

Rugalmasság

Ne egy szakmára épüljön.

Szolgáltatástípusokkal működjön.

Skálázhatóság

Ugyanaz a rendszer kezeljen:

1 dolgozót
100 dolgozót

külön fejlesztés nélkül.

Automatizálhatóság

Minden ismétlődő folyamat automatizálható legyen.

6. Core Product Pillars
Pillar 1
Booking Engine

A foglalási motor.

Feladata:

időpontok kezelése
erőforrások kezelése
kapacitás kezelés
várólista kezelés
Pillar 2
Customer Management

CRM modul.

Feladata:

ügyféladatok
előzmények
dokumentumok
kommunikáció
Pillar 3
Service Management

Szolgáltatások kezelése.

Támogatott típusok:

Időpont nélküli
videó
recept
igazolás
Idősávos
masszázs
konzultáció
Esemény
pilates
workshop
Erőforrás
terem
eszköz
Jóváhagyásos
előszűrt konzultáció
Ajánlatkérés
rendezvény
komplex szolgáltatás

Pillar 4
Team Management

Dolgozók kezelése.

munkarend
jogosultságok
telephelyek
foglalhatóság
Pillar 5
Business Automation

Automatikus:

email
sms
értesítések
számlázás
online fizetés
7. SaaS Architecture

A rendszer teljesen Multi-Tenant architektúrában működik.

Hierarchia:

Super Admin

Tenant

 ├─ Tenant Admin
 ├─ Manager
 ├─ Employee
 └─ Customer

Minden cég saját:

ügyfelekkel
szolgáltatásokkal
dolgozókkal
beállításokkal

rendelkezik.

8. Permission Model

A rendszer nem szerepkör alapú.

A rendszer:

Permission
 ↓
Role
 ↓
User

modellt használ.

Példák:

booking.view
booking.create
booking.edit

customer.view
customer.edit

service.create

billing.view
9. Monetizációs modell

> **Frissítve (2026-06):** az eredeti előfizetés-alapú, többlépcsős csomag-ötlet (Basic/Professional/Business/Enterprise) **elvetve**. Igazság-forrás: `10-arazasi-modell-jutalek.md`.

**Forgalom-alapú jutalék (Pay-as-you-grow).** A foglalási motor mindenkinek **ingyenes**, egyetlen base plan. A slot4u a tenant havi **jutalékköteles foglalási forgalma** után számol jutalékot (ingyenes küszöb felett, marginálisan, havi plafonnal), és **havi jutalékszámlán** szedi be. Nincs fix havidíj, nincs csomagválasztás.

10. Feature Flag System

Tenantenként kapcsolható modulok.

Például:

Google Meet

Online Payment

Invoice

SMS

API

Documents

Reports

AI Assistant
11. Long-Term Vision

A SLOT4U ne egyszerű foglalási rendszer legyen.

Hanem:

Operating System for Service Businesses

Egy olyan platform, amely egy szolgáltató vállalkozás teljes működését képes támogatni:

foglalás
ügyfélkezelés
számlázás
fizetés
kommunikáció
dokumentumkezelés
riportok
automatizációk
AI alapú asszisztencia