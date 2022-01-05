# MikroTik & UCRM Service API

## Cum functioneaza? / How it works?

RO:
Acest plugin a fost dezvoltat cu scopul de a crea automat device-uri pe platforma UISP (NMS), instante PPP in Winbox, dupa crearea unui serviciu de client. De asemenea, acestea se sterg odata cu suspendarea serviciului sau terminarea acestuia.

Informatiile care completeaza campurile din "/ppp/secret" sunt preluate automat din platforma UNMS/CRM folosind API, ca de exemplu: adresa serviciului, numele clientului etc.

EN:
This plugin will automatically create UISP (NMS) devices and PPP Instances in Winbox after a service is created for a client. Also, they are deleted when the service is suspended or ended.

The information that fills in the fields in "/ppp/secret" is automatically retrieved from the UNMS/CRM platform using the API, such as: service address, customer name etc.
