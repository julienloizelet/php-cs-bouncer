<!-- START doctoc generated TOC please keep comment here to allow auto update -->
<!-- DON'T EDIT THIS SECTION, INSTEAD RE-RUN doctoc TO UPDATE -->
**Table of Contents**

- [CrowdSecBouncer\RestClient](#crowdsecbouncer%5Crestclient)
  - [Methods](#methods)
    - [RestClient::__construct](#restclient__construct)
    - [RestClient::configure](#restclientconfigure)
    - [RestClient::request](#restclientrequest)

<!-- END doctoc generated TOC please keep comment here to allow auto update -->

# CrowdSecBouncer\RestClient  

The low level REST Client.





## Methods

| Name | Description |
|------|-------------|
|[__construct](#restclient__construct)||
|[configure](#restclientconfigure)|Configure this instance.|
|[request](#restclientrequest)|Send an HTTP request using the file_get_contents and parse its JSON result if any.|




### RestClient::__construct  

**Description**

```php
 __construct (void)
```

 

 

**Parameters**

`This function has no parameters.`

**Return Values**

`void`


<hr />


### RestClient::configure  

**Description**

```php
public configure (void)
```

Configure this instance. 

 

**Parameters**

`This function has no parameters.`

**Return Values**

`void`


<hr />


### RestClient::request  

**Description**

```php
public request (void)
```

Send an HTTP request using the file_get_contents and parse its JSON result if any. 

 

**Parameters**

`This function has no parameters.`

**Return Values**

`void`


**Throws Exceptions**


`\BouncerException`
> when the reponse status is not 2xx

<hr />

