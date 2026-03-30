PideInfo is a web application that helps citizens in Spain manage **freedom of information requests** (*solicitudes de acceso a información pública*) submitted to public administrations under Spain's transparency laws.

For more insights about the project, check README.md

If you need the architecture info, check docs/architecture.md
The flow of information requests is outlined in docs/request-workflow.md
The flow of complaints when the request response is not what the user expects is in docs/complaint-workflow.md
The document processing is in docs/document-processing.md
The portal sync agent (Python) and JWT authentication are in docs/agent.md
The inbound email pipeline is in docs/inbound-email.md


# Development keys:

- All migrations must be idempotent.
- Any updates must be reflected in the docs. 