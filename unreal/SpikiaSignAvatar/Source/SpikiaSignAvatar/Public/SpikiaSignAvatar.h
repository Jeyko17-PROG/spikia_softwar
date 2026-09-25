// Modulo raiz del plugin. No tiene logica propia mas alla de inicializar/liberar el
// subsistema de WebSockets (FWebSocketsModule), que Unreal exige cargar explicitamente antes
// de poder crear un IWebSocket - ver StartupModule().

#pragma once

#include "CoreMinimal.h"
#include "Modules/ModuleManager.h"

class FSpikiaSignAvatarModule : public IModuleInterface
{
public:
	virtual void StartupModule() override;
	virtual void ShutdownModule() override;
};
