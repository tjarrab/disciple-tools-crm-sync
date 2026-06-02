To determine the source platform of a contact's messages (such as TikTok, Facebook, or Instagram), you can query the Respond.io API for the specific channels connected to that contact.  
You can do this using the **List Contact Channels** endpoint.

### **1\. The API Endpoint**

HTTP  
GET https://api.respond.io/v2/contact/{identifier}/channels

The {identifier} path parameter allows you to look up the contact using three different methods:

* **Contact ID:** id:12345  
* **Email:** email:user@example.com  
* **Phone:** phone:+1234567890

### **2\. Example cURL Request**

Bash  
curl \--request GET \\  
  \--url https://api.respond.io/v2/contact/{identifier}/channels \\  
  \--header 'Authorization: Bearer YOUR\_API\_TOKEN'

### **3\. Locating the Source in the Response**

The API will return a JSON response containing an items array. Inside this array, every channel object has a **source** field. This specific key dictates exactly which messaging platform the contact is using.

JSON  
{  
  "items": \[  
    {  
      "id": 98765,  
      "name": "My Facebook Page",  
      "source": "facebook",   
      "meta": {},  
      "lastMessageTime": 1680000000,  
      "lastIncomingMessageTime": 1680000000,  
      "created\_at": 1670000000  
    },  
    {  
      "id": 98766,  
      "name": "My TikTok Account",  
      "source": "tiktok",   
      "meta": {},  
      "lastMessageTime": 1680001000,  
      "lastIncomingMessageTime": 1680001000,  
      "created\_at": 1670001000  
    }  
  \],  
  "pagination": {  
    "next": "...",  
    "previous": "..."  
  }  
}

By parsing the source string (which will return values like "facebook", "tiktok", "instagram", etc.), you can conditionally route your logic or accurately identify where that user's incoming messages are originating.